<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadProductTag;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lead management (admin CRM). Every admin sees every lead. All "today" figures use
 * the Bangladesh day. Every action is written to lead_activities, which drives the
 * daily contact count and each lead's timeline.
 */
class LeadController extends Controller
{
    private const TZ = 'Asia/Dhaka';

    private function success($message, $data = null, int $code = 200)
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $code);
    }

    private function failed($message, $errors = null, int $code = 400)
    {
        return response()->json(['status' => 'failed', 'message' => $message, 'errors' => $errors], $code);
    }

    private function actor(Request $request): ?User
    {
        return $request->attributes->get('api_user') ?? $request->user();
    }

    private function todayRange(): array
    {
        $start = Carbon::now(self::TZ)->startOfDay();

        return [$start->copy()->utc(), $start->copy()->endOfDay()->utc()];
    }

    private function log(Lead $lead, ?User $actor, string $type, ?string $from = null, ?string $to = null, ?string $note = null): void
    {
        LeadActivity::create([
            'lead_id' => $lead->id,
            'admin_id' => $actor?->id,
            'type' => $type,
            'from_value' => $from !== null ? mb_substr($from, 0, 100) : null,
            'to_value' => $to !== null ? mb_substr($to, 0, 100) : null,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    /** First contact moves a New lead to Contacted. */
    private function touchContact(Lead $lead, ?User $actor): void
    {
        $lead->last_contacted_at = now();
        if ($lead->status === 'new') {
            $lead->status = 'contacted';
            $this->log($lead, $actor, 'status_changed', 'new', 'contacted');
        }
    }

    private function withRelations(): array
    {
        return [
            'productTag:id,name',
            'user:id,name,phone,banned,user_type',
            'creator:id,name',
            'assignee:id,name',
        ];
    }

    private function matchDropshipper(string $normalized): ?User
    {
        $variants = Lead::phoneVariants($normalized);

        return $variants === [] ? null : User::where('user_type', 'dropshipper')->whereIn('phone', $variants)->first(['id', 'name', 'phone', 'banned']);
    }

    private function duplicateOf(string $normalized, ?int $exceptId = null): ?Lead
    {
        return Lead::where('phone_normalized', $normalized)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->first(['id', 'name', 'phone', 'status']);
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => ['nullable', 'string', 'max:150'],
            'phone' => [$req, 'string', 'max:30'],
            'role' => ['sometimes', Rule::in(Lead::ROLES)],
            'status' => ['sometimes', Rule::in(Lead::STATUSES)],
            'followup_tag' => ['nullable', Rule::in(Lead::FOLLOWUP_TAGS)],
            'page_link' => ['nullable', 'string', 'max:500'],
            'product_tag_id' => ['nullable', 'integer', 'exists:lead_product_tags,id'],
            'note' => ['nullable', 'string', 'max:5000'],
            'next_call_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * GET /leads
     * Filters: search, status, role, followup_tag, product_tag_id, source,
     * schedule (scheduled | today), date_from, date_to (created, Y-m-d), page, per_page.
     */
    public function index(Request $request)
    {
        try {
            $request->validate([
                'status' => ['nullable', Rule::in(array_merge(Lead::STATUSES, ['all']))],
                'role' => ['nullable', Rule::in(array_merge(Lead::ROLES, ['all']))],
                'followup_tag' => ['nullable', Rule::in(array_merge(Lead::FOLLOWUP_TAGS, ['all']))],
                'schedule' => ['nullable', Rule::in(['scheduled', 'today', 'all'])],
                'source' => ['nullable', Rule::in(array_merge(Lead::SOURCES, ['all']))],
                'product_tag_id' => ['nullable', 'integer'],
                'search' => ['nullable', 'string', 'max:100'],
                'date_from' => ['nullable', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'date_format:Y-m-d'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $query = Lead::query()->with($this->withRelations());
            $this->applyFilters($query, $request);

            if (in_array($request->get('schedule'), ['scheduled', 'today'], true)) {
                $query->orderBy('next_call_at');
            }
            $leads = $query->orderByDesc('created_at')->orderByDesc('id')->paginate((int) $request->get('per_page', 20));

            return $this->success('Leads fetched successfully', $leads);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            report($e);
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        foreach (['status', 'role', 'followup_tag', 'source'] as $field) {
            $value = $request->get($field);
            if ($value !== null && $value !== '' && $value !== 'all') {
                $query->where($field, $value);
            }
        }
        if ($request->filled('product_tag_id')) {
            $query->where('product_tag_id', (int) $request->get('product_tag_id'));
        }

        $schedule = $request->get('schedule');
        if ($schedule === 'scheduled') {
            $query->whereNotNull('next_call_at');
        } elseif ($schedule === 'today') {
            // Due today or overdue, still open.
            [, $endOfToday] = $this->todayRange();
            $query->whereNotNull('next_call_at')->where('next_call_at', '<=', $endOfToday)
                ->whereNotIn('status', ['converted', 'cancelled']);
        }

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $digits = Lead::normalizePhone($search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('page_link', 'like', "%{$search}%")
                    ->orWhere('note', 'like', "%{$search}%");
                if (strlen($digits) >= 3) {
                    $q->orWhere('phone_normalized', 'like', '%' . ltrim($digits, '0') . '%');
                }
            });
        }

        foreach (['date_from' => 'startOfDay', 'date_to' => 'endOfDay'] as $param => $edge) {
            if ($request->filled($param)) {
                $boundary = Carbon::createFromFormat('Y-m-d', $request->get($param), self::TZ)->{$edge}()->utc();
                $query->where('created_at', $param === 'date_from' ? '>=' : '<=', $boundary);
            }
        }
    }

    /**
     * GET /leads/stats
     * Totals by status, plus today's collected leads, today's contacted leads (a lead
     * counts once per day) and calls scheduled for today.
     */
    public function stats()
    {
        try {
            [$start, $end] = $this->todayRange();
            $byStatus = Lead::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

            $data = ['total' => (int) $byStatus->sum()];
            foreach (Lead::STATUSES as $status) {
                $data[$status] = (int) ($byStatus[$status] ?? 0);
            }
            $data['today_created'] = Lead::whereBetween('created_at', [$start, $end])->count();
            $data['today_contacted'] = LeadActivity::whereBetween('created_at', [$start, $end])
                ->whereIn('type', Lead::CONTACT_TYPES)
                ->whereHas('lead')
                ->distinct()->count('lead_id');
            $data['scheduled_today'] = Lead::whereNotNull('next_call_at')->where('next_call_at', '<=', $end)
                ->whereNotIn('status', ['converted', 'cancelled'])->count();

            return $this->success('Lead stats fetched successfully', $data);
        } catch (\Throwable $e) {
            report($e);
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /** POST /leads */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate($this->rules(true));
            $normalized = Lead::normalizePhone($validated['phone']);
            if (strlen($normalized) < 6) {
                return $this->failed('Validation failed', ['phone' => ['Enter a valid phone number.']], 422);
            }
            if ($existing = $this->duplicateOf($normalized)) {
                return $this->failed('This phone number is already a lead.', [
                    'phone' => ['This phone number is already a lead.'],
                    'existing_lead_id' => $existing->id,
                ], 422);
            }

            $actor = $this->actor($request);
            $dropshipper = $this->matchDropshipper($normalized);

            $lead = DB::transaction(function () use ($validated, $normalized, $actor, $dropshipper) {
                $lead = Lead::create(array_merge($validated, [
                    'phone_normalized' => $normalized,
                    'role' => $validated['role'] ?? 'dropshipper',
                    'status' => $validated['status'] ?? 'new',
                    'source' => 'manual',
                    'user_id' => $dropshipper?->id,
                    'name' => $validated['name'] ?? $dropshipper?->name,
                    'created_by' => $actor?->id,
                    'converted_at' => ($validated['status'] ?? null) === 'converted' ? now() : null,
                ]));
                $this->log($lead, $actor, 'created', null, $lead->status);
                if ($lead->next_call_at) {
                    $this->log($lead, $actor, 'scheduled', null, $lead->next_call_at->toDateTimeString());
                }

                return $lead;
            });

            return $this->success(
                $dropshipper ? 'Lead saved. This number is already a registered dropshipper; the lead is linked to the account.' : 'Lead saved.',
                ['lead' => $lead->load($this->withRelations()), 'matched_dropshipper' => $dropshipper],
                201
            );
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            report($e);
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /** GET /leads/{id}  lead with its activity timeline */
    public function show($id)
    {
        $lead = Lead::with(array_merge($this->withRelations(), ['activities.admin:id,name']))->find($id);

        return $lead ? $this->success('Lead fetched successfully', $lead) : $this->failed('Lead not found', null, 404);
    }

    /** PUT /leads/{id} */
    public function update(Request $request, $id)
    {
        try {
            $lead = Lead::find($id);
            if (!$lead) {
                return $this->failed('Lead not found', null, 404);
            }
            $validated = $request->validate($this->rules(false));
            $actor = $this->actor($request);

            if (array_key_exists('phone', $validated)) {
                $normalized = Lead::normalizePhone($validated['phone']);
                if (strlen($normalized) < 6) {
                    return $this->failed('Validation failed', ['phone' => ['Enter a valid phone number.']], 422);
                }
                if ($existing = $this->duplicateOf($normalized, $lead->id)) {
                    return $this->failed('This phone number is already a lead.', [
                        'phone' => ['This phone number is already a lead.'],
                        'existing_lead_id' => $existing->id,
                    ], 422);
                }
                $validated['phone_normalized'] = $normalized;
                if (!$lead->user_id && ($dropshipper = $this->matchDropshipper($normalized))) {
                    $validated['user_id'] = $dropshipper->id;
                }
            }

            DB::transaction(function () use ($lead, $validated, $actor) {
                $before = $lead->only(['status', 'followup_tag', 'next_call_at', 'note']);
                $lead->fill($validated);

                if ($lead->isDirty('status')) {
                    $lead->converted_at = $lead->status === 'converted' ? ($lead->converted_at ?? now()) : null;
                    $this->log($lead, $actor, 'status_changed', $before['status'], $lead->status);
                }
                if ($lead->isDirty('followup_tag')) {
                    $this->log($lead, $actor, 'followup_changed', $before['followup_tag'], $lead->followup_tag);
                    $this->touchContact($lead, $actor);
                }
                if ($lead->isDirty('next_call_at')) {
                    $this->log($lead, $actor, 'scheduled', $before['next_call_at']?->toDateTimeString(), $lead->next_call_at?->toDateTimeString());
                }
                if ($lead->isDirty('note')) {
                    $this->log($lead, $actor, 'note', null, null, $this->addedText($before['note'], $lead->note));
                    $this->touchContact($lead, $actor);
                }
                if ($lead->isDirty(['name', 'phone', 'role', 'page_link', 'product_tag_id', 'assigned_to'])) {
                    $this->log($lead, $actor, 'updated');
                }
                $lead->save();
            });

            return $this->success('Lead updated.', $lead->fresh($this->withRelations()));
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            report($e);
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /** Only the new part of a note when text was appended, otherwise the whole note. */
    private function addedText(?string $before, ?string $after): ?string
    {
        $before = (string) $before;
        $after = (string) $after;
        if ($before !== '' && str_starts_with($after, $before)) {
            return trim(mb_substr($after, mb_strlen($before))) ?: null;
        }

        return $after !== '' ? $after : null;
    }

    /** DELETE /leads/{id}  (soft delete) */
    public function destroy($id)
    {
        $lead = Lead::find($id);
        if (!$lead) {
            return $this->failed('Lead not found', null, 404);
        }
        $lead->delete();

        return $this->success('Lead deleted.');
    }

    /**
     * POST /leads/{id}/contact  {channel: call | whatsapp}
     * Logged when an admin taps Call or WhatsApp; counts as a contact today.
     */
    public function contact(Request $request, $id)
    {
        try {
            $validated = $request->validate(['channel' => ['required', Rule::in(['call', 'whatsapp'])]]);
            $lead = Lead::find($id);
            if (!$lead) {
                return $this->failed('Lead not found', null, 404);
            }
            $actor = $this->actor($request);
            DB::transaction(function () use ($lead, $actor, $validated) {
                $this->log($lead, $actor, $validated['channel']);
                $this->touchContact($lead, $actor);
                $lead->save();
            });

            return $this->success('Contact logged.', $lead->fresh($this->withRelations()));
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        }
    }

    /**
     * POST /leads/from-dropshipper/{userId}
     * Turn an inactive dropshipper into a lead. Returns the existing lead if there is one.
     */
    public function fromDropshipper(Request $request, $userId)
    {
        try {
            $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
            $user = User::where('user_type', 'dropshipper')->find($userId);
            if (!$user) {
                return $this->failed('Dropshipper not found', null, 404);
            }
            $normalized = Lead::normalizePhone($user->phone);
            $existing = Lead::where('user_id', $user->id)
                ->when($normalized !== '', fn ($q) => $q->orWhere('phone_normalized', $normalized))
                ->first();
            if ($existing) {
                if (!$existing->user_id) {
                    $existing->update(['user_id' => $user->id]);
                }

                return $this->success('This dropshipper is already a lead.', ['lead' => $existing->load($this->withRelations()), 'already_exists' => true]);
            }
            if ($normalized === '') {
                return $this->failed('This dropshipper has no phone number, so they cannot be added as a lead.', null, 422);
            }

            $actor = $this->actor($request);
            $lead = DB::transaction(function () use ($user, $normalized, $actor, $validated) {
                $lead = Lead::create([
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'phone_normalized' => $normalized,
                    'role' => 'dropshipper',
                    'status' => 'new',
                    'source' => 'dropshipper',
                    'user_id' => $user->id,
                    'note' => $validated['note'] ?? null,
                    'created_by' => $actor?->id,
                ]);
                $this->log($lead, $actor, 'created', 'dropshipper', 'new', 'Converted from the dropshipper list');

                return $lead;
            });

            return $this->success('Dropshipper added as a lead.', ['lead' => $lead->load($this->withRelations()), 'already_exists' => false], 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        } catch (\Throwable $e) {
            report($e);
            return $this->failed('Something went wrong', ['error' => $e->getMessage()], 500);
        }
    }

    /** GET /leads/tags */
    public function tags()
    {
        return $this->success('Lead tags fetched successfully', LeadProductTag::orderBy('name')->get(['id', 'name']));
    }

    /** POST /leads/tags {name} */
    public function storeTag(Request $request)
    {
        try {
            $validated = $request->validate(['name' => ['required', 'string', 'max:80', 'unique:lead_product_tags,name']]);

            return $this->success('Tag added.', LeadProductTag::create(['name' => trim($validated['name'])]), 201);
        } catch (ValidationException $e) {
            return $this->failed('Validation failed', $e->errors(), 422);
        }
    }
}
