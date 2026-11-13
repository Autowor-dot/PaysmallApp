-- =====================================================================
-- LipaLink / PaySmallApp database schema
--
-- Apply with api/install.php?key=<install_key>  (or paste into phpMyAdmin > SQL).
-- Safe to re-run: every statement uses IF NOT EXISTS / INSERT IGNORE.
--
-- Note: `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` is MariaDB syntax
-- (InfinityFree runs MariaDB). On MySQL 8 the installer skips the
-- "duplicate column" errors these produce on a second run.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Customers (table already exists in production; columns added below)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name     VARCHAR(120)  NOT NULL,
    phone         VARCHAR(20)   NOT NULL,
    email         VARCHAR(190)  NOT NULL,
    password_hash VARCHAR(255)  NOT NULL,
    settings_json TEXT          NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users ADD COLUMN IF NOT EXISTS settings_json TEXT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE users ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- ---------------------------------------------------------------------
-- Sellers / merchant stores
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sellers (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NULL,
    business_name VARCHAR(160) NOT NULL,
    full_name     VARCHAR(120) NOT NULL,
    phone         VARCHAR(20)  NOT NULL,
    email         VARCHAR(190) NOT NULL,
    location      VARCHAR(160) NULL,
    category      VARCHAR(80)  NULL,
    payout_method ENUM('mobile_money','bank') NOT NULL DEFAULT 'mobile_money',
    status        ENUM('pending','verified','suspended') NOT NULL DEFAULT 'pending',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sellers_email (email),
    UNIQUE KEY uq_sellers_phone (phone),
    KEY idx_sellers_user (user_id),
    CONSTRAINT fk_sellers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Marketplace products
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_id             INT UNSIGNED NULL,
    slug                  VARCHAR(120) NOT NULL,
    name                  VARCHAR(200) NOT NULL,
    description           TEXT NULL,
    category              VARCHAR(80)  NOT NULL DEFAULT 'General',
    price                 DECIMAL(14,2) NOT NULL DEFAULT 0,
    installment_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
    installment_frequency ENUM('daily','weekly','biweekly','monthly') NOT NULL DEFAULT 'weekly',
    installments_count    INT UNSIGNED NOT NULL DEFAULT 4,
    image_url             VARCHAR(255) NULL,
    icon                  VARCHAR(80)  NULL,
    stock                 INT UNSIGNED NULL,
    featured              TINYINT(1) NOT NULL DEFAULT 0,
    status                ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_slug (slug),
    KEY idx_products_seller (seller_id),
    KEY idx_products_category (category, status),
    CONSTRAINT fk_products_seller FOREIGN KEY (seller_id) REFERENCES sellers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Installment agreements (proposal + full JSON payload from agreement.html)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS agreements (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    agreement_code         VARCHAR(40)  NOT NULL,
    customer_id            INT UNSIGNED NOT NULL,
    seller_id              INT UNSIGNED NULL,
    seller_type            ENUM('registered','unregistered') NOT NULL DEFAULT 'unregistered',
    seller_name            VARCHAR(160) NULL,
    seller_email           VARCHAR(190) NULL,
    seller_phone           VARCHAR(20)  NULL,
    product_name           VARCHAR(200) NOT NULL,
    total_price            DECIMAL(14,2) NOT NULL DEFAULT 0,
    initial_deposit        DECIMAL(14,2) NOT NULL DEFAULT 0,
    number_of_installments INT UNSIGNED  NOT NULL DEFAULT 1,
    payment_frequency      VARCHAR(30)   NOT NULL DEFAULT 'monthly',
    status                 VARCHAR(40)   NOT NULL DEFAULT 'pending_seller_approval',
    response_note          VARCHAR(500)  NULL,
    responded_at           DATETIME      NULL,
    payload_json           LONGTEXT      NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agreements_code (agreement_code),
    KEY idx_agreements_customer (customer_id),
    KEY idx_agreements_seller (seller_id),
    KEY idx_agreements_seller_email (seller_email),
    CONSTRAINT fk_agreements_customer FOREIGN KEY (customer_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_agreements_seller   FOREIGN KEY (seller_id)   REFERENCES sellers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE agreements ADD COLUMN IF NOT EXISTS response_note VARCHAR(500) NULL;
ALTER TABLE agreements ADD COLUMN IF NOT EXISTS responded_at DATETIME NULL;

-- ---------------------------------------------------------------------
-- Installment plans (what the dashboard shows as "My Active Installment Plans")
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS installment_plans (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id           INT UNSIGNED NOT NULL,
    agreement_id          INT UNSIGNED NULL,
    product_name          VARCHAR(200) NOT NULL,
    seller_name           VARCHAR(160) NULL,
    product_image         VARCHAR(255) NULL,
    total_price           DECIMAL(14,2) NOT NULL DEFAULT 0,
    amount_paid           DECIMAL(14,2) NOT NULL DEFAULT 0,
    next_payment_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,
    next_payment_due_date DATE NULL,
    status                ENUM('pending','active','overdue','completed','cancelled') NOT NULL DEFAULT 'active',
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_plans_customer (customer_id),
    KEY idx_plans_agreement (agreement_id),
    CONSTRAINT fk_plans_customer  FOREIGN KEY (customer_id)  REFERENCES users (id)      ON DELETE CASCADE,
    CONSTRAINT fk_plans_agreement FOREIGN KEY (agreement_id) REFERENCES agreements (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Scheduled installment payments (what the dashboard shows as "Upcoming Payments")
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS installment_payments (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_id            INT UNSIGNED NOT NULL,
    customer_id        INT UNSIGNED NOT NULL,
    installment_number INT UNSIGNED NOT NULL DEFAULT 1,
    amount_due         DECIMAL(14,2) NOT NULL DEFAULT 0,
    due_date           DATE NOT NULL,
    status             ENUM('upcoming','due','overdue','paid') NOT NULL DEFAULT 'upcoming',
    paid_at            DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_payments_plan (plan_id),
    KEY idx_payments_customer_due (customer_id, due_date),
    CONSTRAINT fk_payments_plan     FOREIGN KEY (plan_id)     REFERENCES installment_plans (id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_customer FOREIGN KEY (customer_id) REFERENCES users (id)             ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Transactions / receipts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transactions (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id        INT UNSIGNED NOT NULL,
    plan_id            INT UNSIGNED NULL,
    payment_id         INT UNSIGNED NULL,
    transaction_code   VARCHAR(40)  NOT NULL,
    product_name       VARCHAR(200) NOT NULL,
    seller_name        VARCHAR(160) NULL,
    amount             DECIMAL(14,2) NOT NULL DEFAULT 0,
    payment_method     VARCHAR(40)  NOT NULL DEFAULT 'Mobile Money',
    payer_phone        VARCHAR(20)  NULL,
    provider_reference VARCHAR(100) NULL,
    status             ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at       DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_transactions_code (transaction_code),
    KEY idx_transactions_customer (customer_id, created_at),
    CONSTRAINT fk_transactions_customer FOREIGN KEY (customer_id) REFERENCES users (id)                ON DELETE CASCADE,
    CONSTRAINT fk_transactions_plan     FOREIGN KEY (plan_id)     REFERENCES installment_plans (id)    ON DELETE SET NULL,
    CONSTRAINT fk_transactions_payment  FOREIGN KEY (payment_id)  REFERENCES installment_payments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE transactions ADD COLUMN IF NOT EXISTS provider_reference VARCHAR(100) NULL;

-- ---------------------------------------------------------------------
-- Notifications
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    type       VARCHAR(40)  NOT NULL DEFAULT 'general',
    title      VARCHAR(160) NOT NULL,
    message    TEXT NULL,
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user (user_id, is_read),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Password reset tokens (only the SHA-256 hash of the token is stored)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_resets_token (token_hash),
    KEY idx_resets_user (user_id),
    CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Sample marketplace products with photos
-- (catalog photos from cdn.dummyjson.com; local photos under assets/img)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO products (slug, name, description, category, price, installment_amount, installment_frequency, installments_count, icon, image_url, featured)
VALUES
    ('samsung-galaxy-smartphone', 'Samsung Galaxy S10',          '128GB storage, 6.1-inch AMOLED display, dual SIM. Brand new with 1-year warranty.', 'Electronics & Phones', 1150000, 48000, 'weekly', 24, 'fa-solid fa-mobile-screen', 'https://cdn.dummyjson.com/product-images/smartphones/samsung-galaxy-s10/thumbnail.webp', 1),
    ('iphone-13-pro',             'iPhone 13 Pro 128GB',         'Apple A15 Bionic, ProMotion display, triple camera. Sealed, with 12-month warranty.', 'Electronics & Phones', 2850000, 120000, 'weekly', 24, 'fa-solid fa-mobile-screen', 'https://cdn.dummyjson.com/product-images/smartphones/iphone-13-pro/thumbnail.webp', 1),
    ('realme-c35',                'Realme C35 64GB',             'Affordable 6.6-inch smartphone with 5000mAh battery and 50MP camera.', 'Electronics & Phones', 390000, 16500, 'weekly', 24, 'fa-solid fa-mobile-screen', 'https://cdn.dummyjson.com/product-images/smartphones/realme-c35/thumbnail.webp', 0),
    ('home-solar-power-system',   'Home Solar Power System',     'Complete 200W solar kit with battery, inverter, 4 LED bulbs and phone charging.', 'Home Solar & Appliances', 350000, 15000, 'weekly', 24, 'fa-solid fa-solar-panel', 'assets/img/solar-home-tanzania.jpg', 1),
    ('business-laptop-i5',        'Dell XPS 13 Business Laptop', 'Intel Core i5, 8GB RAM, 256GB SSD, Windows 11. Ideal for office and students.', 'Electronics & Phones', 2400000, 100000, 'weekly', 24, 'fa-solid fa-laptop', 'https://cdn.dummyjson.com/product-images/laptops/new-dell-xps-13-9300-laptop/thumbnail.webp', 1),
    ('lenovo-yoga-920',           'Lenovo Yoga 920 2-in-1',      '13.9-inch touchscreen convertible, Core i7, 16GB RAM, 512GB SSD.', 'Electronics & Phones', 2050000, 85000, 'weekly', 24, 'fa-solid fa-laptop', 'https://cdn.dummyjson.com/product-images/laptops/lenovo-yoga-920/thumbnail.webp', 0),
    ('samsung-galaxy-tab-s8',     'Samsung Galaxy Tab S8 Plus',  '12.4-inch AMOLED tablet with S Pen. Perfect for students and business.', 'Electronics & Phones', 1550000, 65000, 'weekly', 24, 'fa-solid fa-tablet-screen-button', 'https://cdn.dummyjson.com/product-images/tablets/samsung-galaxy-tab-s8-plus-grey/thumbnail.webp', 0),
    ('double-door-fridge',        'Microwave Oven 25L',          'Digital microwave with grill, 10 power levels and child lock. 2-year warranty.', 'Home Solar & Appliances', 260000, 22000, 'monthly', 12, 'fa-solid fa-temperature-low', 'https://cdn.dummyjson.com/product-images/kitchen-accessories/microwave-oven/thumbnail.webp', 0),
    ('electric-stove',            'Electric Stove (2 plates)',   'Energy-efficient double hot plate with adjustable heat control.', 'Home Solar & Appliances', 140000, 12000, 'monthly', 12, 'fa-solid fa-fire-burner', 'https://cdn.dummyjson.com/product-images/kitchen-accessories/electric-stove/thumbnail.webp', 0),
    ('queen-bed-and-mattress',    'Queen Bed & Mattress Set',    'Solid wood queen bed with 10-inch orthopaedic mattress. Free delivery in Dar es Salaam.', 'Furniture & Decor', 1200000, 100000, 'monthly', 12, 'fa-solid fa-bed', 'https://cdn.dummyjson.com/product-images/furniture/annibale-colombo-bed/thumbnail.webp', 1),
    ('three-seater-sofa',         'Three-Seater Fabric Sofa',    'Comfortable living-room sofa with hardwood frame and washable covers.', 'Furniture & Decor', 950000, 80000, 'monthly', 12, 'fa-solid fa-couch', 'https://cdn.dummyjson.com/product-images/furniture/annibale-colombo-sofa/thumbnail.webp', 0),
    ('boda-boda-motorcycle',      'Boda Boda Motorcycle 150cc',  'Fuel-efficient 150cc motorcycle with registration support. Ideal for riders starting a business.', 'Transport', 2900000, 60000, 'weekly', 48, 'fa-solid fa-motorcycle', 'assets/img/boda-boda-bagamoyo.jpg', 1);

-- Add photos to products created by an earlier version of this file
UPDATE products SET image_url = 'https://cdn.dummyjson.com/product-images/smartphones/samsung-galaxy-s10/thumbnail.webp', name = 'Samsung Galaxy S10' WHERE slug = 'samsung-galaxy-smartphone' AND (image_url IS NULL OR image_url = '');
UPDATE products SET image_url = 'assets/img/solar-home-tanzania.jpg' WHERE slug = 'home-solar-power-system' AND (image_url IS NULL OR image_url = '');
UPDATE products SET image_url = 'https://cdn.dummyjson.com/product-images/laptops/new-dell-xps-13-9300-laptop/thumbnail.webp', name = 'Dell XPS 13 Business Laptop' WHERE slug = 'business-laptop-i5' AND (image_url IS NULL OR image_url = '');
UPDATE products SET image_url = 'https://cdn.dummyjson.com/product-images/kitchen-accessories/microwave-oven/thumbnail.webp', name = 'Microwave Oven 25L', description = 'Digital microwave with grill, 10 power levels and child lock. 2-year warranty.', price = 260000, installment_amount = 22000 WHERE slug = 'double-door-fridge' AND (image_url IS NULL OR image_url = '');
UPDATE products SET image_url = 'https://cdn.dummyjson.com/product-images/furniture/annibale-colombo-bed/thumbnail.webp' WHERE slug = 'queen-bed-and-mattress' AND (image_url IS NULL OR image_url = '');
UPDATE products SET image_url = 'assets/img/boda-boda-bagamoyo.jpg' WHERE slug = 'boda-boda-motorcycle' AND (image_url IS NULL OR image_url = '');
