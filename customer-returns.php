<?php
ob_start(); // Add this line immediately after opening PHP tag
session_start();
date_default_timezone_set('Asia/Manila');
require_once 'config/database.php';
require_once 'includes/functions.php';

// PHPMailer for notifications
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/PHPMailer/src/Exception.php';
require 'PHPMailer/PHPMailer/src/PHPMailer.php';
require 'PHPMailer/PHPMailer/src/SMTP.php';

requireLogin();

if (isset($_SESSION['user_type']) && in_array($_SESSION['user_type'], ['admin', 'seller'])) {
    header('Location: login_customer.php');
    exit();
}

$customerId = $_SESSION['user_id'];
$message = '';
$error = '';

$orderIdFromUrl = isset($_GET['order_id']) ? (int)$_GET['order_id'] : null;
$selectedProductIds = isset($_GET['product_ids']) ? array_filter(array_map('intval', explode(',', $_GET['product_ids']))) : null;

// Debug output
error_log("DEBUG - Order ID: " . $orderIdFromUrl);
error_log("DEBUG - Selected Product IDs: " . print_r($selectedProductIds, true));
error_log("DEBUG - Raw product_ids GET param: " . ($_GET['product_ids'] ?? 'not set'));

// Handle form submission with robust backend logic from process-return.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_return'])) {
    $orderId = intval($_POST['order_id']);
    $reason = trim($_POST['return_reason']);
    $mainIssue = isset($_POST['main_issue']) ? trim($_POST['main_issue']) : '';
    $subIssue = isset($_POST['sub_issue']) ? trim($_POST['sub_issue']) : '';
    
    // Debug form submission
    error_log("DEBUG - Form submission:");
    error_log("DEBUG - Order ID: " . $orderId);
    error_log("DEBUG - Selected Product ID: " . ($_POST['selected_product_id'] ?? 'not set'));
    error_log("DEBUG - Main Issue: " . $mainIssue);
    error_log("DEBUG - Sub Issue: " . $subIssue);
    error_log("DEBUG - Reason: " . $reason);
    
    // Validate main issue
    if (empty($mainIssue)) {
        $error = 'Please select what happened to your order';
    }
    // Validate reason
    elseif (empty($reason)) {
        $error = 'Please provide details about the issue';
    }
    // FIXED: Proper file validation - now accepts 1-3 photos
    elseif (!isset($_FILES['return_photos']) || !is_array($_FILES['return_photos']['name'])) {
        $error = 'No photos uploaded. Please upload at least 1 photo (maximum 3).';
    }
    else {
        $totalFiles = count($_FILES['return_photos']['name']);
        if ($totalFiles === 0) {
            $error = 'Please upload at least 1 photo showing the issue (maximum 3)';
        }
        // FIXED: Better file counting and validation
        else {
            $validFiles = [];
            $uploadErrors = [];
            $actualFileCount = 0;

            // First, count actual files being uploaded
            for ($i = 0; $i < $totalFiles; $i++) {
                if (isset($_FILES['return_photos']['error'][$i]) && 
                    $_FILES['return_photos']['error'][$i] !== UPLOAD_ERR_NO_FILE &&
                    !empty($_FILES['return_photos']['name'][$i])) {
                    $actualFileCount++;
                }
            }

            // Validate we have 1-3 files
            if ($actualFileCount < 1 || $actualFileCount > 3) {
                $error = "Please upload 1-3 photos (found $actualFileCount)";
            }
            else {
                for ($i = 0; $i < $totalFiles; $i++) {
                    if (!isset($_FILES['return_photos']['error'][$i])) {
                        continue;
                    }
                    
                    $errorCode = $_FILES['return_photos']['error'][$i];
                    
                    // Skip empty slots
                    if ($errorCode === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    
                    $tmpName = $_FILES['return_photos']['tmp_name'][$i];
                    $fileName = $_FILES['return_photos']['name'][$i];
                    $fileSize = $_FILES['return_photos']['size'][$i];
                    
                    // Check for upload errors
                    if ($errorCode !== UPLOAD_ERR_OK) {
                        $uploadErrors[] = "File upload error: $fileName (code: $errorCode)";
                        continue;
                    }
                    
                    // Validate temp file exists
                    if (!file_exists($tmpName) || !is_uploaded_file($tmpName)) {
                        $uploadErrors[] = "$fileName is not a valid upload";
                        continue;
                    }
                    
                    // Validate file type
                    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (!in_array($extension, $allowedExtensions)) {
                        $uploadErrors[] = "$fileName - Invalid type";
                        continue;
                    }
                    
                    // Validate file size (5MB max)
                    if ($fileSize > 5 * 1024 * 1024) {
                        $uploadErrors[] = "$fileName exceeds 5MB";
                        continue;
                    }
                    
                    // Validate it's an image
                    $imageInfo = @getimagesize($tmpName);
                    if ($imageInfo === false) {
                        $uploadErrors[] = "$fileName is not a valid image";
                        continue;
                    }
                    
                    $validFiles[] = [
                        'tmp_name' => $tmpName,
                        'name' => $fileName,
                        'size' => $fileSize,
                        'extension' => $extension
                    ];
                }

                // Final validation - now accepts 1-3 files
                if (count($validFiles) < 1 || count($validFiles) > 3) {
                    $msg = count($validFiles) . " valid files (need 1-3 photos).";
                    if (!empty($uploadErrors)) {
                        $msg .= " Errors: " . implode("; ", $uploadErrors);
                    }
                    $error = $msg;
                }
                else {
                    try {
                        // Verify order belongs to user and is delivered
                        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? AND status = 'delivered'");
                        $stmt->execute([$orderId, $customerId]);
                        $order = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$order) {
                            $error = 'Invalid order or order not eligible for return';
                        }
                        else {
                            // Get product ID from form (for single product) or from selected products
                            $productId = null;
                            $quantity = 1;
                            $sellerId = null;
                            
                            if (isset($_POST['selected_product_id'])) {
                                $productId = (int)$_POST['selected_product_id'];
                                $quantity = (int)$_POST['selected_quantity'];
                            } else {
                                // Fallback: get first product from order
                                $stmt = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ? LIMIT 1");
                                $stmt->execute([$orderId]);
                                $orderItem = $stmt->fetch(PDO::FETCH_ASSOC);
                                if ($orderItem) {
                                    $productId = $orderItem['product_id'];
                                    $quantity = $orderItem['quantity'];
                                }
                            }
                            
                            if (!$productId) {
                                throw new Exception('Product not found for this order');
                            }
                            
                            // Get seller_id from the product - FIXED
                            $stmt = $pdo->prepare("SELECT seller_id FROM products WHERE id = ?");
                            $stmt->execute([$productId]);
                            $sellerId = $stmt->fetchColumn();
                            if (!$sellerId) {
                                throw new Exception('Seller not found for this product');
                            }

                            // Build full reason with issue type
                            $issueTypes = [
                                'damaged' => 'Received Damaged Item(s)',
                                'incorrect' => 'Received Incorrect Item(s)',
                                'not_received' => 'Did Not Receive Some/All Items'
                            ];
                            
                            $subIssueTypes = [
                                'damaged_item' => 'Damaged item',
                                'defective_item' => 'Product is defective or does not work',
                                'parcel_not_delivered' => 'Parcel not delivered',
                                'missing_parts' => 'Missing part of the order',
                                'empty_parcel' => 'Empty parcel'
                            ];
                            
                            $fullReason = ($issueTypes[$mainIssue] ?? 'Unknown');
                            if (!empty($subIssue)) {
                                $fullReason .= " - " . ($subIssueTypes[$subIssue] ?? 'Unknown');
                            }
                            $fullReason .= " | " . $reason;

                              $pdo->beginTransaction();
        
                            // Insert return request
                            $stmt = $pdo->prepare("INSERT INTO return_requests (order_id, customer_id, product_id, seller_id, quantity, reason, status, created_at) 
                                                  VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
                            $stmt->execute([$orderId, $customerId, $productId, $sellerId, $quantity, $fullReason]);
                            $returnRequestId = $pdo->lastInsertId();
                            // Update order status to return_requested
                            $stmt = $pdo->prepare("UPDATE orders SET status = 'return_requested' WHERE id = ?");
                            $stmt->execute([$orderId]);
                      

                            if (!$returnRequestId) {
                                throw new Exception('Failed to create return request');
                            }

                            // Create upload directory with proper error handling
                            $uploadDir = 'uploads/returns/' . $returnRequestId . '/';
                            
                            if (!is_dir($uploadDir)) {
                                if (!@mkdir($uploadDir, 0755, true)) {
                                    throw new Exception('Failed to create upload directory: ' . $uploadDir);
                                }
                            }
                            
                            // Ensure directory is writable
                            if (!is_writable($uploadDir)) {
                                throw new Exception('Upload directory is not writable: ' . $uploadDir);
                            }

                            // FIXED: Upload files one at a time with unique names
                            $uploadedPhotos = [];
                            $uploadFailures = [];
                            $photoCounter = 1;

                            foreach ($validFiles as $file) {
                                $tmpName = $file['tmp_name'];
                                $extension = $file['extension'];
                                $originalName = $file['name'];
                                
                                // Verify file still exists before processing
                                if (!file_exists($tmpName) || !is_uploaded_file($tmpName)) {
                                    $uploadFailures[] = "$originalName: Temp file no longer valid";
                                    continue;
                                }
                                
                                // Generate unique filename using counter + timestamp + random
                                $uniqueId = uniqid('', true); // More entropy
                                $filename = sprintf(
                                    'return_%d_photo%d_%s.%s',
                                    $returnRequestId,
                                    $photoCounter,
                                    str_replace('.', '', $uniqueId),
                                    $extension
                                );
                                $filepath = $uploadDir . $filename;
                                
                                // Ensure no duplicate
                                if (file_exists($filepath)) {
                                    $filename = sprintf(
                                        'return_%d_photo%d_%s_%d.%s',
                                        $returnRequestId,
                                        $photoCounter,
                                        str_replace('.', '', $uniqueId),
                                        time(),
                                        $extension
                                    );
                                    $filepath = $uploadDir . $filename;
                                }
                                
                                // Move file
                                if (!move_uploaded_file($tmpName, $filepath)) {
                                    $uploadFailures[] = "$originalName: Failed to save";
                                    continue;
                                }
                                
                                // Verify file was saved correctly
                                clearstatcache(true, $filepath);
                                if (!file_exists($filepath) || filesize($filepath) === 0) {
                                    $uploadFailures[] = "$originalName: File empty after save";
                                    @unlink($filepath);
                                    continue;
                                }
                                
                                // Verify it's still a valid image
                                if (@getimagesize($filepath) === false) {
                                    $uploadFailures[] = "$originalName: Corrupted after upload";
                                    @unlink($filepath);
                                    continue;
                                }
                                
                                // Insert into database
                                try {
                                    $stmt = $pdo->prepare(
                                        "INSERT INTO return_photos (return_request_id, photo_path, uploaded_at) 
                                         VALUES (?, ?, NOW())"
                                    );
                                    
                                    if (!$stmt->execute([$returnRequestId, $filepath])) {
                                        $uploadFailures[] = "$originalName: Database error";
                                        @unlink($filepath);
                                        continue;
                                    }
                                    
                                    $uploadedPhotos[] = $filepath;
                                    $photoCounter++;
                                    
                                } catch (PDOException $e) {
                                    $uploadFailures[] = "$originalName: " . $e->getMessage();
                                    @unlink($filepath);
                                    continue;
                                }
                            }

                            // Verify 1-3 photos uploaded
                            if (count($uploadedPhotos) < 1 || count($uploadedPhotos) > 3) {
                                $pdo->rollback();
                                
                                // Delete uploaded files
                                foreach ($uploadedPhotos as $photo) {
                                    @unlink($photo);
                                }
                                @rmdir($uploadDir);
                                
                                $errorMsg = count($uploadedPhotos) . " photos saved (need 1-3).";
                                if (!empty($uploadFailures)) {
                                    $errorMsg .= " Issues: " . implode("; ", array_slice($uploadFailures, 0, 2));
                                }
                                throw new Exception($errorMsg);
                            }

                            // Get seller info for app notification
                            $stmt = $pdo->prepare("
                                SELECT u.id as seller_id, u.first_name, u.last_name, p.name as product_name
                                FROM order_items oi
                       JOIN products p ON oi.product_id = p.id 
                       JOIN users u ON p.seller_id = u.id
                                WHERE oi.order_id = ?
                                LIMIT 1
                            ");
                            $stmt->execute([$orderId]);
                            $sellerInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                            // Get customer info
                            $stmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
$stmt->execute([$customerId]);
                            $customerInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                            // Create app notification for seller
                            // Create app notification for seller
                         
                            if ($sellerInfo) {
                                $stmt = $pdo->prepare("
                                    INSERT INTO order_status_history (order_id, status, notes, updated_by, created_at) 
                                    VALUES (?, 'return_requested', ?, ?, NOW())
                                ");
                                $notificationMessage = "New return request for Order #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . " - " . htmlspecialchars($sellerInfo['product_name']) . ". Please review and respond.";
                                $stmt->execute([$orderId, $notificationMessage, $sellerInfo['seller_id']]);
                                
                                // Create seller notification for return request
                                if (file_exists('includes/seller_notification_functions.php')) {
                                    require_once 'includes/seller_notification_functions.php';
                                    if (function_exists('createReturnRequestNotification')) {
                                        createReturnRequestNotification(
                                            $sellerInfo['seller_id'],
                                            $returnRequestId,
                                            $orderId,
                                            $sellerInfo['product_name']
                                        );
                                    }
                                }
                            }  // <-- ADD THIS CLOSING BRACE

                            $pdo->commit();

                            // Send email notification to customer only
                            if ($customerInfo) {
                                $mail = new PHPMailer(true);
                                try {
                                    $mail->isSMTP();
                                    $mail->Host = 'smtp.gmail.com';
                                    $mail->SMTPAuth = true;
                                    $mail->Username = 'jhongujol1299@gmail.com';
                                    $mail->Password = 'ljdo ohkv pehx idkv';
                                    $mail->SMTPSecure = "ssl";
                                    $mail->Port = 465;
                                    
                                    $mail->setFrom('jhongujol1299@gmail.com', 'E-Commerce Store');
                                    $mail->addAddress($customerInfo['email'], $customerInfo['first_name'] . ' ' . $customerInfo['last_name']);
                                    
                                    $mail->isHTML(true);
                                    $mail->Subject = '✅ Return Request Submitted - Order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT);
                                    
                                    $mail->Body = "
                                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;'>
                                        <div style='text-align: center; border-bottom: 2px solid #28a745; padding-bottom: 20px; margin-bottom: 20px;'>
                                            <h1 style='color: #28a745; margin: 0;'>✅ Return Request Received</h1>
                                        </div>
                                        
                                        <p style='color: #666; font-size: 16px;'>Dear {$customerInfo['first_name']},</p>
                                        <p style='color: #666; font-size: 16px;'>Your return request has been successfully submitted with " . count($uploadedPhotos) . " photo(s) and is now under review.</p>
                                        
                                        <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                                            <p style='margin: 10px 0;'><strong>Order Number:</strong> #" . str_pad($orderId, 6, '0', STR_PAD_LEFT) . "</p>
                                            <p style='margin: 10px 0;'><strong>Status:</strong> <span style='color: #ffc107; font-weight: bold;'>PENDING REVIEW</span></p>
                                            <p style='margin: 10px 0;'><strong>Submitted:</strong> " . date('F j, Y g:i A') . "</p>
                                        </div>
                                        
                                        <div style='background: #d1ecf1; border-left: 4px solid #17a2b8; padding: 15px; margin: 20px 0;'>
                                            <p style='color: #0c5460; margin: 0;'>The seller will review your request within 3-5 business days. You'll receive an email notification once a decision is made.</p>
                                        </div>
                                    </div>";
                                    
                                    $mail->send();
                                    
                                } catch (Exception $e) {
                                    error_log("Return notification email failed: {$mail->ErrorInfo}");
                                }
                            }

                            $message = 'Your return request has been successfully submitted! Order #' . str_pad($orderId, 6, '0', STR_PAD_LEFT) . ' has been sent for review. We\'ll notify you via email within 3-5 business days.';
                            
                            // Redirect to user dashboard with success message
                            $_SESSION['return_success_message'] = $message;

                            // Redirect to user dashboard with success message
                            header('Location: user-dashboard.php?status=return_requested');
                            exit;
                        }
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollback();
                        }
                        
                        // Clean up on error
                        if (isset($returnRequestId) && isset($uploadDir) && is_dir($uploadDir)) {
                            $files = glob($uploadDir . '*');
                            foreach ($files as $file) {
                                if (is_file($file)) {
                                    @unlink($file);
                                }
                            }
                            @rmdir($uploadDir);
                        }
                        
                        error_log("Return request error: " . $e->getMessage());
                        $error = 'Error processing return request: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Get order information if provided
$orderInfo = null;
$orderProducts = [];

if ($orderIdFromUrl) {
    // Get order basic info
    $stmt = $pdo->prepare("
        SELECT o.*, CONCAT(u.first_name, ' ', u.last_name) as seller_name
        FROM orders o 
        LEFT JOIN order_items oi ON o.id = oi.order_id 
        LEFT JOIN products p ON oi.product_id = p.id 
        LEFT JOIN users u ON p.seller_id = u.id
        WHERE o.id = ? AND o.user_id = ?
        GROUP BY o.id
    ");
    $stmt->execute([$orderIdFromUrl, $customerId]);
    $orderInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($orderInfo) {
        // Get products based on selection
        if ($selectedProductIds && !empty($selectedProductIds)) {
            $placeholders = str_repeat('?,', count($selectedProductIds) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT oi.*, p.name as product_name, p.price, p.seller_id, p.description,
                       p.image_url, CONCAT(u.first_name, ' ', u.last_name) as seller_name
                FROM order_items oi 
                JOIN products p ON oi.product_id = p.id 
                LEFT JOIN users u ON p.seller_id = u.id
                WHERE oi.order_id = ? AND oi.product_id IN ($placeholders)
                ORDER BY oi.id
            ");
            $params = array_merge([$orderIdFromUrl], $selectedProductIds);
            $stmt->execute($params);
        } else {
            $stmt = $pdo->prepare("
                SELECT oi.*, p.name as product_name, p.price, p.seller_id, p.description,
                       p.image_url, CONCAT(u.first_name, ' ', u.last_name) as seller_name
                FROM order_items oi 
                JOIN products p ON oi.product_id = p.id 
                LEFT JOIN users u ON p.seller_id = u.id
                WHERE oi.order_id = ?
                ORDER BY oi.id
            ");
            $stmt->execute([$orderIdFromUrl]);
        }
        $orderProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug output
        error_log("DEBUG - Fetched order products: " . print_r($orderProducts, true));
        
        // Debug image URLs specifically
        foreach ($orderProducts as $index => $product) {
            error_log("DEBUG - Product $index image_url: " . ($product['image_url'] ?? 'NULL'));
        }
    }
}

include 'includes/header.php';
?>

<style>
:root {
    --primary-dark: #130325;
    --accent-yellow: #FFD736;
    --text-dark: #130325;
}

body {
    background: #ffffff !important;
    color: var(--text-dark);
    margin: 0;
    padding: 0;
}

/* Main Container */
.returns-container {
    max-width: 1200px;
    width: 100%;
    margin: 20px auto 40px;
    padding: 0 20px;
}

/* Page Title - Upper Left */
.page-title {
    color: var(--text-dark);
    font-size: 1.6rem;
    font-weight: 800;
    margin: 0 0 30px 0;
    text-align: left;
}

/* Return Form Container */
.return-form {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06);
}

/* Request Return Header - Dark Purple */
.return-title {
    background: var(--primary-dark);
    color: var(--accent-yellow);
    font-size: 1.1rem;
    font-weight: 800;
    margin: -24px -24px 18px -24px;
    padding: 14px 20px;
    border-radius: 12px 12px 0 0;
    text-align: left;
    text-transform: none;
    letter-spacing: 0;
}

/* Order Information Container */
.order-info-container {
    background: #ffffff;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 25px;
}

.order-info-container h4 {
    color: var(--text-dark);
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 15px;
}

/* Order Basic Info - Minimized */
.order-basic-info {
    background: #f8f9fa;
    border-radius: 6px;
    padding: 12px 15px;
    border-left: 3px solid var(--primary-dark);
    margin-bottom: 20px;
}

.order-basic-info p {
    margin: 3px 0;
    color: #6c757d;
    font-size: 0.85rem;
    font-weight: 500;
}

/* Product Details Section */
.order-details-section h5 {
    color: var(--text-dark);
    font-size: 1.05rem;
    font-weight: 700;
    margin-bottom: 15px;
}

/* Product Options - Multiple Products */
.product-options {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.product-option {
    position: relative;
}

.product-option input[type="radio"] {
    position: absolute;
    opacity: 0;
}

.product-option-label {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    background: #ffffff;
}

.product-option-label:hover {
    border-color: var(--primary-dark);
    background: #f8f9fa;
}

.product-option input[type="radio"]:checked + .product-option-label {
    border-color: var(--primary-dark);
    background: rgba(19, 3, 37, 0.05);
}

.product-option-image {
    width: 80px;
    height: 80px;
    flex-shrink: 0;
}

.product-option-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 6px;
    border: 2px solid #e9ecef;
}

.no-image {
    width: 100%;
    height: 100%;
    background: #e9ecef;
    border: 2px dashed #adb5bd;
    border-radius: 6px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #6c757d;
    font-size: 0.8rem;
    font-weight: 500;
    text-align: center;
    padding: 10px;
}

.product-option-info h6 {
    margin: 0 0 8px 0;
    color: var(--text-dark);
    font-size: 1rem;
    font-weight: 600;
}

.product-option-info p {
    margin: 4px 0;
    color: #6c757d;
    font-size: 0.9rem;
}

/* Single Product Display */
.order-details {
    display: flex;
    gap: 20px;
    align-items: flex-start;
}

.product-image {
    width: 120px;
    height: 120px;
    flex-shrink: 0;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid #e9ecef;
}

.product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.product-info {
    flex: 1;
}

.product-info h5 {
    color: var(--text-dark);
    font-size: 1.1rem;
    font-weight: 700;
    margin-bottom: 10px;
}

.product-info p {
    margin: 5px 0;
    color: #495057;
    font-size: 0.95rem;
}

/* Instructions */
.instructions {
    background: var(--primary-dark);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 25px;
}

.instructions h4 {
    color: var(--accent-yellow);
    font-size: 1.05rem;
    font-weight: 700;
    margin-bottom: 12px;
}

.instructions ol {
    margin: 0;
    padding-left: 20px;
    color: #ffffff;
}

.instructions li {
    margin-bottom: 6px;
    font-size: 0.9rem;
    line-height: 1.5;
}

/* Image Upload Section */
.image-upload-section {
    margin-bottom: 25px;
}

.image-upload-section > label {
    display: block;
    color: var(--text-dark);
    font-weight: 700;
    margin-bottom: 12px;
    font-size: 1rem;
}

.image-upload-grid {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
}

.image-upload-box {
    flex: 1;
    aspect-ratio: 1;
    max-height: 150px;
    border: 2px dashed #e9ecef;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e9ecef;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.image-upload-box.clickable {
    cursor: pointer;
}

.image-upload-box.clickable:hover {
    border-color: var(--primary-dark);
    background: #dee2e6;
    transform: translateY(-3px);
}

.image-upload-box.has-image {
    border-style: solid;
    border-color: var(--primary-dark);
}

.image-upload-box img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.upload-icon {
    display: flex;
    flex-direction: column;
    align-items: center;
    color: #6c757d;
    text-align: center;
}

.upload-icon i {
    font-size: 2rem;
    margin-bottom: 8px;
    color: var(--primary-dark);
}

.upload-icon span {
    font-size: 0.85rem;
}

.remove-image {
    position: absolute;
    top: 5px;
    right: 5px;
    background: #dc3545;
    color: white;
    border: none;
    width: 28px;
    height: 28px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    opacity: 0;
    transition: all 0.3s ease;
}

.image-upload-box:hover .remove-image {
    opacity: 1;
}

.remove-image:hover {
    background: #c82333;
}

.file-help {
    display: block;
    text-align: center;
    color: #6c757d;
    font-size: 0.85rem;
    font-style: italic;
}

/* Form Groups */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    color: var(--text-dark);
    font-weight: 700;
    margin-bottom: 8px;
    font-size: 0.95rem;
}

/* Custom Dropdown Styling */
.form-group select {
    width: 100%;
    padding: 14px 45px 14px 18px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 500;
    transition: all 0.3s ease;
    background: #ffffff;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23130325' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 15px center;
    background-size: 18px;
    appearance: none;
    cursor: pointer;
    color: var(--text-dark);
}

.form-group select:focus,
.form-group select:hover {
    outline: none;
    border-color: var(--primary-dark);
    background-color: #f8f9fa;
}

.form-group textarea {
    width: 100%;
    padding: 14px 18px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background: #ffffff;
    resize: vertical;
    font-family: inherit;
}

.form-group textarea:focus {
    outline: none;
    border-color: var(--primary-dark);
    background-color: #f8f9fa;
}

/* Submit Button */
.submit-btn {
    background: var(--primary-dark);
    color: var(--accent-yellow);
    border: none;
    padding: 12px 24px;
    border-radius: 6px;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    width: auto;
    margin-top: 20px;
    margin-bottom: 20px;
    margin-left: auto;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    display: block;
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(19, 3, 37, 0.3);
    background: #1a0a3e;
}

/* Alert Messages */
.alert {
    padding: 18px 22px;
    border-radius: 8px;
    margin-bottom: 25px;
    font-weight: 600;
        font-size: 1rem;
    border-left: 4px solid;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border-left-color: #28a745;
}

.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border-left-color: #dc3545;
}

/* Responsive */
@media (max-width: 768px) {
    .returns-container {
        margin-top: 12px;
    }
    
    .return-form {
        padding: 20px 16px;
    }
    
    .return-title {
        margin: -16px -16px 16px -16px;
        padding: 12px 16px;
        font-size: 1rem;
    }
    
    .image-upload-grid {
        flex-direction: column;
    }
    
    .image-upload-box {
        max-height: 180px;
    }
    
    .order-details {
        flex-direction: column;
    }
    
    .product-image {
        width: 100%;
        height: 200px;
    }
}
</style>

<div class="returns-container">
    <h1 class="page-title">Returns & Refunds</h1>

    <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- Return Request Form -->
        <div class="return-form">
        <h2 class="return-title"><i class="fas fa-undo-alt"></i> Request a Return</h2>
        
        <form method="POST" enctype="multipart/form-data" id="returnForm">
            <?php if ($orderInfo && !empty($orderProducts)): ?>
                <!-- Order Information -->
                <div class="order-info-container">
                    <h4>Order Information</h4>
                    <div class="order-basic-info">
                        <p><strong>Order #:</strong> <?php echo str_pad($orderInfo['id'], 6, '0', STR_PAD_LEFT); ?></p>
                        <p><strong>Order Date:</strong> <?php echo date('M d, Y', strtotime($orderInfo['created_at'])); ?></p>
                </div>

                    <!-- Product Details Section -->
                    <div class="order-details-section">
                        <h5>Product Details for Return:</h5>
                        
                        <?php if (count($orderProducts) > 1): ?>
                            <!-- Multiple Products - Show Radio Selection -->
                            <div class="product-options">
                                <?php foreach ($orderProducts as $index => $product): ?>
                                    <div class="product-option">
                                        <input type="radio" 
                                               name="selected_product" 
                                               id="product_<?php echo $index; ?>" 
                                               value="<?php echo $product['product_id']; ?>" 
                                               data-quantity="<?php echo $product['quantity']; ?>"
                                               <?php echo $index === 0 ? 'checked' : ''; ?>>
                                        <label for="product_<?php echo $index; ?>" class="product-option-label">
                                            <div class="product-option-image">
                                                <?php if ($product['image_url'] && !empty($product['image_url'])): ?>
                                                    <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                                         alt="<?php echo htmlspecialchars($product['product_name']); ?>"
                                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                    <div class="no-image" style="display: none;">No Image</div>
                                                <?php else: ?>
                                                    <div class="no-image">
                                                        <i class="fas fa-image" style="font-size: 2rem; margin-bottom: 5px;"></i>
                                                        <span>No Image</span>
                </div>
                                                <?php endif; ?>
                </div>
                                            <div class="product-option-info">
                                                <h6><?php echo htmlspecialchars($product['product_name']); ?></h6>
                                                <p><strong>Price:</strong> ₱<?php echo number_format($product['price'], 2); ?></p>
                                                <p><strong>Quantity:</strong> <?php echo $product['quantity']; ?></p>
                                                <p><strong>Seller:</strong> <?php echo htmlspecialchars($product['seller_name'] ?? 'Unknown'); ?></p>
        </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <!-- Single Product - Show Direct Display -->
                            <?php $product = $orderProducts[0]; ?>
                            <div class="order-details">
                                <div class="product-image">
                                    <?php if ($product['image_url'] && !empty($product['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                             alt="<?php echo htmlspecialchars($product['product_name']); ?>"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div class="no-image" style="display: none;">No Image</div>
                                    <?php else: ?>
                                        <div class="no-image">
                                            <i class="fas fa-image" style="font-size: 3rem; margin-bottom: 10px;"></i>
                                            <span>No Image</span>
                                        </div>
                                    <?php endif; ?>
                        </div>
                        <div class="product-info">
                                    <h5><?php echo htmlspecialchars($product['product_name']); ?></h5>
                                    <p><strong>Seller:</strong> <?php echo htmlspecialchars($product['seller_name'] ?? 'Unknown'); ?></p>
                                    <p><strong>Price:</strong> ₱<?php echo number_format($product['price'], 2); ?></p>
                                    <p><strong>Quantity Ordered:</strong> <?php echo $product['quantity']; ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
            </div>

                <!-- Hidden Form Fields -->
                <input type="hidden" name="order_id" value="<?php echo $orderInfo['id']; ?>">
                <input type="hidden" name="product_id" id="selected_product_id" value="<?php echo $orderProducts[0]['product_id']; ?>">
                <input type="hidden" name="quantity" id="selected_quantity" value="<?php echo $orderProducts[0]['quantity']; ?>">
        <?php else: ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> No valid order found. Please return to your dashboard and try again.
            </div>
        <?php endif; ?>

            <!-- Instructions -->
            <div class="instructions">
                <h4><i class="fas fa-info-circle"></i> How to Request a Return:</h4>
                <ol>
                    <li>Upload up to 3 photos of the product showing any defects or issues</li>
                    <li>Select the reason for your return from the dropdown below</li>
                    <li>Provide additional details about the issue</li>
                    <li>Submit your request for review</li>
                </ol>
    </div>

            <!-- Image Upload Section -->
            <div class="image-upload-section">
                <label><i class="fas fa-images"></i> Upload Product Images</label>
                <div class="image-upload-grid">
                    <div class="image-upload-box clickable" id="upload-box-1" onclick="document.getElementById('file-input-hidden').click();">
                        <div class="upload-icon">
                            <i class="fas fa-plus-circle"></i>
                            <span>Add Photo</span>
                        </div>
                        </div>
                    <div class="image-upload-box" id="upload-box-2"></div>
                    <div class="image-upload-box" id="upload-box-3"></div>
                    </div>
                <input type="file" id="file-input-hidden" name="return_photos[]" multiple accept="image/*" style="display: none;">
                <small class="file-help"><i class="fas fa-info-circle"></i> 1-3 images, 5MB each (JPEG, PNG, GIF, WebP)</small>
                </div>

            <!-- Main Issue Selection -->
            <div class="form-group">
                <label for="main_issue"><i class="fas fa-exclamation-triangle"></i> What happened to your order?</label>
                <select name="main_issue" id="main_issue" required>
                    <option value="">Select what happened...</option>
                    <option value="damaged">Received Damaged Item(s)</option>
                    <option value="incorrect">Received Incorrect Item(s)</option>
                    <option value="not_received">Did Not Receive Some/All Items</option>
                </select>
        </div>

            <!-- Sub Issue Selection (Hidden by default) -->
            <div class="form-group" id="sub_issue_group" style="display: none;">
                <label for="sub_issue"><i class="fas fa-list"></i> Specific Issue</label>
                <select name="sub_issue" id="sub_issue">
                    <option value="">Select specific issue...</option>
                </select>
            </div>

            <!-- Return Reason -->
            <div class="form-group">
                <label for="return_reason"><i class="fas fa-comment-alt"></i> Please provide more details</label>
                <textarea name="return_reason" id="return_reason" rows="4" placeholder="Describe the issue in detail (e.g., what damage did you find, what item was incorrect, which items are missing, etc.)" required></textarea>
            </div>

            <!-- Submit Button -->
            <button type="submit" name="submit_return" class="submit-btn">
                <i class="fas fa-paper-plane"></i> Submit
            </button>
        </form>
    </div>
</div>

<script>
// Image Upload Functionality
let uploadedImages = [];
const maxImages = 3; // Updated to match backend requirement

// Main Issue and Sub Issue Handling
document.addEventListener('DOMContentLoaded', function() {
    const mainIssueSelect = document.getElementById('main_issue');
    const subIssueGroup = document.getElementById('sub_issue_group');
    const subIssueSelect = document.getElementById('sub_issue');
    
    if (mainIssueSelect && subIssueGroup && subIssueSelect) {
        mainIssueSelect.addEventListener('change', function() {
            const mainIssue = this.value;
            
            // Clear sub issue options
            subIssueSelect.innerHTML = '<option value="">Select specific issue...</option>';
            
            if (mainIssue === 'damaged') {
                subIssueGroup.style.display = 'block';
                subIssueSelect.innerHTML = `
                    <option value="">Select specific issue...</option>
                    <option value="damaged_item">Damaged item</option>
                    <option value="defective_item">Product is defective or does not work</option>
                `;
            } else if (mainIssue === 'not_received') {
                subIssueGroup.style.display = 'block';
                subIssueSelect.innerHTML = `
                    <option value="">Select specific issue...</option>
                    <option value="parcel_not_delivered">Parcel not delivered</option>
                    <option value="missing_parts">Missing part of the order</option>
                    <option value="empty_parcel">Empty parcel</option>
                `;
            } else if (mainIssue === 'incorrect') {
                subIssueGroup.style.display = 'none';
                subIssueSelect.value = '';
            } else {
                subIssueGroup.style.display = 'none';
                subIssueSelect.value = '';
            }
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('file-input-hidden');
    
    if (!fileInput) {
        console.error('File input not found!');
        return;
    }
    
    // File input change handler
    fileInput.addEventListener('change', function(e) {
        const files = Array.from(e.target.files);
        
        if (files.length === 0) return;
        
        const availableSlots = maxImages - uploadedImages.length;
        if (files.length > availableSlots) {
            alert(`You can only upload ${availableSlots} more image(s). Maximum is ${maxImages} images total.`);
            e.target.value = '';
            return;
        }
        
        let validFiles = 0;
        files.forEach((file) => {
            if (uploadedImages.length >= maxImages) return;
            
            // Check file size (5MB max)
            if (file.size > 5 * 1024 * 1024) {
                alert(`${file.name} is too large. Maximum size is 5MB.`);
                return;
            }
            
            // Check file type
            if (!file.type.startsWith('image/')) {
                alert(`${file.name} is not an image file.`);
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(event) {
                uploadedImages.push({
                    file: file,
                    dataUrl: event.target.result
                });
                validFiles++;
                
                if (validFiles === files.length || uploadedImages.length === maxImages) {
                    displayImages();
                }
            };
            reader.onerror = function() {
                alert('Error reading file. Please try again.');
            };
            reader.readAsDataURL(file);
        });
        
        e.target.value = '';
    });
    
    // Handle product selection for multiple products
    const productRadios = document.querySelectorAll('input[name="selected_product"]');
    if (productRadios.length > 0) {
        productRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.checked) {
                    document.getElementById('selected_product_id').value = this.value;
                    document.getElementById('selected_quantity').value = this.dataset.quantity;
                }
            });
        });
    }
});

function displayImages() {
    // Box 1: Always show + button
    const box1 = document.getElementById('upload-box-1');
    if (box1) {
        box1.innerHTML = `
            <div class="upload-icon">
                <i class="fas fa-plus-circle"></i>
                <span>Add Photo</span>
            </div>
        `;
    }
    
    // Box 2: Show first image or empty
    const box2 = document.getElementById('upload-box-2');
    if (box2) {
        if (uploadedImages.length >= 1) {
            box2.classList.add('has-image');
            box2.innerHTML = `
                <img src="${uploadedImages[0].dataUrl}" alt="Upload 1">
                <button type="button" class="remove-image" onclick="removeImage(0); event.stopPropagation();">
                    <i class="fas fa-times"></i>
                </button>
            `;
        } else {
            box2.classList.remove('has-image');
            box2.innerHTML = '';
        }
    }
    
    // Box 3: Show second image or empty
    const box3 = document.getElementById('upload-box-3');
    if (box3) {
        if (uploadedImages.length >= 2) {
            box3.classList.add('has-image');
            box3.innerHTML = `
                <img src="${uploadedImages[1].dataUrl}" alt="Upload 2">
                <button type="button" class="remove-image" onclick="removeImage(1); event.stopPropagation();">
                    <i class="fas fa-times"></i>
                </button>
            `;
        } else {
            box3.classList.remove('has-image');
            box3.innerHTML = '';
        }
    }
    
}

function removeImage(index) {
    uploadedImages.splice(index, 1);
    displayImages();
}

// Form submission handling
const returnForm = document.getElementById('returnForm');
if (returnForm) {
    returnForm.addEventListener('submit', function(e) {
        if (uploadedImages.length >= 1 && uploadedImages.length <= 3) {
            try {
                const dt = new DataTransfer();
                uploadedImages.forEach(image => {
                    dt.items.add(image.file);
                });
                
                const fileInput = document.getElementById('file-input-hidden');
                fileInput.files = dt.files;
            } catch (error) {
                console.error('Error attaching files:', error);
                alert('Error preparing images for upload. Please try again.');
                e.preventDefault();
            }
        } else {
            alert('Please upload 1-3 photos showing the issue.');
            e.preventDefault();
        }
    });
}
</script>

<?php include 'includes/footer.php'; ?>
