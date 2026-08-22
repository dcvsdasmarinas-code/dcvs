-- Create database (if not exists)
CREATE DATABASE IF NOT EXISTS dcvs_system;
USE dcvs_system;

-- Drop existing tables if they exist (in reverse order of dependencies)
DROP TABLE IF EXISTS confirmed_cases;
DROP TABLE IF EXISTS alerts;
DROP TABLE IF EXISTS announcements;
DROP TABLE IF EXISTS adoption_applications;
DROP TABLE IF EXISTS adoption_listings;
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS pets;
DROP TABLE IF EXISTS barangays;
DROP TABLE IF EXISTS users;

-- 1. USERS TABLE (User Account Mgmt)
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    reference_id VARCHAR(20) UNIQUE,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    first_name VARCHAR(50) NULL,
    last_name VARCHAR(50) NULL,
    role ENUM('resident', 'barangay', 'staff', 'admin') NOT NULL,
    barangay_id INT NULL,
    phone VARCHAR(20),
    birthdate DATE NULL,
    gender ENUM('male', 'female', 'prefer_not') NULL,
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    status ENUM('active', 'inactive') DEFAULT 'active',
    avatar_url VARCHAR(255) NULL,
    archived_at TIMESTAMP NULL,
    password_updated_at TIMESTAMP NULL
);

-- 2. BARANGAYS TABLE (for geo-mapping and jurisdiction)
CREATE TABLE barangays (
    barangay_id INT AUTO_INCREMENT PRIMARY KEY,
    barangay_name VARCHAR(100) NOT NULL,
    district VARCHAR(100),
    coordinates_lat DECIMAL(10, 8),
    coordinates_long DECIMAL(11, 8),
    boundary_polygon TEXT
);

-- 3. PET PROFILES TABLE (Pet Profile Mgmt)
CREATE TABLE pets (
    pet_id INT AUTO_INCREMENT PRIMARY KEY,
    reference_id VARCHAR(20) UNIQUE,
    owner_id INT NOT NULL,
    pet_name VARCHAR(50) NOT NULL,
    pet_type ENUM('dog', 'cat', 'other') NOT NULL,
    breed VARCHAR(50),
    age INT,
    weight DECIMAL(5,2) NULL,
    gender ENUM('male', 'female'),
    color VARCHAR(50),
    date_of_birth DATE NULL,
    vaccination_status TEXT,
    sterilization_status ENUM('sterilized', 'unsterilized') DEFAULT 'unsterilized',
    allergies TEXT NULL,
    current_medications TEXT NULL,
    microchip_id VARCHAR(50),
    photo_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at TIMESTAMP NULL,
    FOREIGN KEY (owner_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- 4. REPORTS TABLE (Reporting Module)
CREATE TABLE reports (
    report_id INT AUTO_INCREMENT PRIMARY KEY,
    reference_id VARCHAR(20) UNIQUE,
    reporter_id INT NOT NULL,
    report_type ENUM('stray', 'rabies', 'injured', 'dead') NOT NULL,
    description TEXT NOT NULL,
    location_address TEXT NOT NULL,
    coordinates_lat DECIMAL(10, 8),
    coordinates_long DECIMAL(11, 8),
    barangay_id INT,
    barangay_notes TEXT,
    rejection_reason TEXT,
    urgency_level ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    photo_url VARCHAR(255),
    status ENUM('pending', 'validated', 'rejected', 'confirmed', 'scheduled', 'resolved') DEFAULT 'pending',
    validated_by INT NULL,
    validated_at TIMESTAMP NULL,
    barangay_clearance_issued BOOLEAN DEFAULT FALSE,
    clearance_issued_at TIMESTAMP NULL,
    confirmed_by INT NULL,
    confirmed_at TIMESTAMP NULL,
    scheduled_at DATETIME NULL,
    dcvs_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (reporter_id) REFERENCES users(user_id),
    FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id),
    FOREIGN KEY (validated_by) REFERENCES users(user_id),
    FOREIGN KEY (confirmed_by) REFERENCES users(user_id)
);

-- 5. APPOINTMENTS TABLE (Appointment Mgmt)
CREATE TABLE appointments (
    appointment_id INT AUTO_INCREMENT PRIMARY KEY,
    reference_id VARCHAR(20) UNIQUE,
    user_id INT NOT NULL,
    appointment_type ENUM('adoption', 'kapon', 'vaccination', 'checkup') NOT NULL,
    pet_id INT NULL,
    preferred_date DATE NOT NULL,
    preferred_time TIME NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'completed', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (pet_id) REFERENCES pets(pet_id) ON DELETE SET NULL
);

-- 6. ADOPTION LISTINGS TABLE (Adoption Mgmt)
CREATE TABLE adoption_listings (
    listing_id INT AUTO_INCREMENT PRIMARY KEY,
    pet_id INT NOT NULL,
    listed_by INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    adoption_fee DECIMAL(10, 2),
    requirements TEXT,
    status ENUM('available', 'pending', 'adopted', 'withdrawn') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (pet_id) REFERENCES pets(pet_id),
    FOREIGN KEY (listed_by) REFERENCES users(user_id)
);

-- 7. ADOPTION APPLICATIONS TABLE
CREATE TABLE adoption_applications (
    application_id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    applicant_id INT NOT NULL,
    application_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    interview_date DATE NULL,
    notes TEXT,
    FOREIGN KEY (listing_id) REFERENCES adoption_listings(listing_id),
    FOREIGN KEY (applicant_id) REFERENCES users(user_id)
);

-- 8. ANNOUNCEMENTS TABLE (CMS Announcements & Advisories)
CREATE TABLE announcements (
    announcement_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    category ENUM('advisory', 'announcement') NOT NULL DEFAULT 'announcement',
    announcement_type ENUM('general', 'alert', 'rabies_outbreak', 'vaccination_drive') DEFAULT 'general',
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    photo_url VARCHAR(255),
    photo_hover_url VARCHAR(255),
    link_url VARCHAR(255),
    barangay_id INT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expiry_date DATE NULL,
    status ENUM('draft', 'published') DEFAULT 'published',
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (created_by) REFERENCES users(user_id),
    FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id) ON DELETE SET NULL
);

-- 9. ALERTS TABLE (Geo-Mapping & Alert)
CREATE TABLE alerts (
    alert_id INT AUTO_INCREMENT PRIMARY KEY,
    alert_type ENUM('rabies_case', 'stray_cluster', 'vaccination_reminder', 'emergency') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    location_address TEXT,
    coordinates_lat DECIMAL(10, 8),
    coordinates_long DECIMAL(11, 8),
    barangay_id INT,
    severity ENUM('info', 'warning', 'critical') DEFAULT 'warning',
    affected_radius_km DECIMAL(5, 2) DEFAULT 1.0,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id),
    FOREIGN KEY (created_by) REFERENCES users(user_id)
);

-- 10. CONFIRMED CASES TABLE (for geo-mapping dashboard)
CREATE TABLE confirmed_cases (
    case_id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    case_type ENUM('stray', 'rabies', 'injured', 'dead') NOT NULL,
    location_address TEXT NOT NULL,
    coordinates_lat DECIMAL(10, 8),
    coordinates_long DECIMAL(11, 8),
    barangay_id INT,
    urgency_level ENUM('low', 'medium', 'high', 'critical'),
    status ENUM('active', 'resolved', 'monitoring') DEFAULT 'active',
    confirmed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    notes TEXT,
    FOREIGN KEY (report_id) REFERENCES reports(report_id),
    FOREIGN KEY (barangay_id) REFERENCES barangays(barangay_id)
);

-- 11. PET DOCUMENTS TABLE (Vaccination certs, lab results, clearances)
CREATE TABLE IF NOT EXISTS pet_documents (
    document_id   INT AUTO_INCREMENT PRIMARY KEY,
    pet_id        INT NOT NULL,
    document_type VARCHAR(50) NOT NULL COMMENT 'e.g. vaccination_cert, lab_result, clearance',
    file_name     VARCHAR(255) NOT NULL,
    file_url      VARCHAR(255) NOT NULL,
    description   TEXT NULL,
    uploaded_by   INT NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pet_id) REFERENCES pets(pet_id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. NOTIFICATIONS TABLE
CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT,
    reference_type VARCHAR(50),
    reference_id INT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- 13. EMAIL LOGS TABLE
CREATE TABLE email_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(100),
    subject VARCHAR(255),
    body TEXT,
    status VARCHAR(50) DEFAULT 'sent',
    error_message TEXT,
    error_detail TEXT,
    sent_by INT,
    sent_to_role VARCHAR(50),
    message_type VARCHAR(50),
    portal_delivered BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sent_by) REFERENCES users(user_id)
);

-- 14. ACTIVITY LOGS TABLE
CREATE TABLE activity_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
);

-- Insert sample barangays
INSERT INTO barangays (barangay_name, district) VALUES
('Barangay 1', 'District 1'),
('Barangay 2', 'District 1'),
('Barangay 3', 'District 2');

-- Run setup.php after importing to hash passwords properly.
INSERT INTO users (username, password, email, full_name, role, status) VALUES
('admin', 'PLACEHOLDER', 'admin@dcvs.gov', 'System Administrator', 'admin', 'active'),
('staff1', 'PLACEHOLDER', 'staff@dcvs.gov', 'DCVS Staff', 'staff', 'active');
