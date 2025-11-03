# SQL to Create Seller Accounts with Brand Names

## First, add the display_name column to the users table (if it doesn't exist):

```sql
ALTER TABLE users ADD COLUMN display_name VARCHAR(100) NULL AFTER last_name;
```

## Single Seller Account with Brand Name:

```sql
INSERT INTO users (username, email, password, first_name, last_name, display_name, user_type, seller_status, is_active, email_verified, agreed_to_terms, agreed_to_privacy)
VALUES ('agripro', 'agripro@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John', 'Smith', 'AgriPro Solutions', 'seller', 'approved', 1, 1, 1, 1);
```

## Multiple Seller Accounts with Brand Names:

```sql
INSERT INTO users (username, email, password, first_name, last_name, display_name, user_type, seller_status, is_active, email_verified, agreed_to_terms, agreed_to_privacy)
VALUES 
('pestshield', 'pestshield@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maria', 'Garcia', 'PestShield Express', 'seller', 'approved', 1, 1, 1, 1),
('cropguard', 'cropguard@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'David', 'Chen', 'CropGuard Premium', 'seller', 'approved', 1, 1, 1, 1),
('greenlife', 'greenlife@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sarah', 'Johnson', 'GreenLife Organics', 'seller', 'approved', 1, 1, 1, 1),
('farmfresh', 'farmfresh@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Michael', 'Brown', 'FarmFresh Direct', 'seller', 'approved', 1, 1, 1, 1),
('agritech', 'agritech@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Lisa', 'Anderson', 'AgriTech Innovations', 'seller', 'approved', 1, 1, 1, 1),
('natureplus', 'natureplus@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Robert', 'Martinez', 'NaturePlus Solutions', 'seller', 'approved', 1, 1, 1, 1),
('harvesthub', 'harvesthub@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jennifer', 'Wilson', 'HarvestHub Co.', 'seller', 'approved', 1, 1, 1, 1),
('cropcare', 'cropcare@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Thomas', 'Lee', 'CropCare Specialists', 'seller', 'approved', 1, 1, 1, 1),
('organifarm', 'organifarm@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Amanda', 'Taylor', 'OrganiFarm Products', 'seller', 'approved', 1, 1, 1, 1),
('fieldguard', 'fieldguard@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Christopher', 'White', 'FieldGuard Systems', 'seller', 'approved', 1, 1, 1, 1);
```

## Notes:
- **Password hash**: `$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi` = `password`
- **Display Name**: This is what customers see as the seller's brand/store name
- **First/Last Name**: Personal names (optional, not shown to customers)
- All accounts are set to `approved` status and ready to use

## More Brand Name Ideas:
- AgriMax Solutions
- PestFree Pro
- CropMaster Plus
- GreenFields Direct
- FarmTech Essentials
- NatureShield Co.
- HarvestPro Systems
- CropZone Premium
- OrganicFarm Express
- FieldMaster Innovations

