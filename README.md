mysql -u root -p
CREATE DATABASE ecom_vendor_api;
SHOW DATABASES;
SHOW TABLES;
DROP TABLE content_images;
USE database_name;
DESCRIBE content_images;
php artisan make:model ProductDiscount -m  
php artisan make:migration UserReview
php artisan route:list
php artisan route:clear
php artisan make:controller ProductDiscountController --resource
php artisan make:model DonationProjectImage -m //table and model
// change a parameter type 
ALTER TABLE transactions MODIFY COLUMN user_name DOUBLE(10, 2); //INT,VARCHAR(255), DOUBLE
ALTER TABLE notifications ADD type VARCHAR(255); // add new column
ALTER TABLE contents ADD is_author_writting boolean; // add new column
ALTER TABLE notifications DROP COLUMN sent_to; // remove a column
DB_PASSWORD=mir0188_2024




