# Payment Details Error - Analysis

## 🔍 **What is `get-payment-details.php`?**

**File:** `paymongo/get-payment-details.php`  
**Type:** API Endpoint (returns JSON, not HTML)  
**Purpose:** Retrieves payment details from PayMongo API using checkout session ID

---

## 🚨 **Possible Error Sources**

### **1. Direct Access Error (Most Likely)**

If you access `get-payment-details.php` directly in browser:
- **Line 20-23:** Returns JSON error if not POST request
  ```json
  {"error": "Method not allowed"}
  ```
- **Line 36-38:** Returns JSON error if missing `checkout_session_id`
  ```json
  {"error": "Missing checkout_session_id"}
  ```

**But:** These return JSON, not red HTML errors.

---

### **2. API Call Errors**

The endpoint can return these errors (lines 113-122):

#### **Error 1: Missing checkout_session_id**
```php
Line 36-38: if (!$input || !isset($input['checkout_session_id'])) {
    throw new Exception('Missing checkout_session_id');
}
```
**Returns:** `{"success": false, "error": "Missing checkout_session_id"}`

#### **Error 2: cURL Error**
```php
Line 63-65: if ($curl_error) {
    throw new Exception("cURL Error: $curl_error");
}
```
**Returns:** `{"success": false, "error": "cURL Error: [error message]"}`

#### **Error 3: PayMongo API Error**
```php
Line 69-71: if ($http_code >= 400) {
    $error_message = $response_data['errors'][0]['detail'] ?? 'Unknown error occurred';
    throw new Exception("PayMongo API Error: $error_message");
}
```
**Returns:** `{"success": false, "error": "PayMongo API Error: [error message]"}`

---

## 📍 **Where Red Errors Are Displayed**

### **If Error Appears on `multi-seller-payment.php`:**

**File:** `paymongo/multi-seller-payment.php`  
**Lines:** 304-313

```php
<?php if (!empty($errors)): ?>
    <div class="error-message">  <!-- ← RED ERROR BOX -->
        <strong>Payment Error:</strong>
        <ul style="margin: 10px 0 0 20px;">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
```

**CSS Styling (Lines 226-233):**
```css
.error-message {
    background: #f8d7da;      /* Light red background */
    color: #721c24;           /* Dark red text */
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
    border-left: 4px solid #dc3545;  /* Red left border */
}
```

**How errors get added:**
- Line 33: `$errors = [];` (initialized)
- Errors would be added if form submission fails
- Currently, no code adds errors to this array in the visible section

---

### **If Error Appears on `multi-seller-checkout.php`:**

**File:** `paymongo/multi-seller-checkout.php`  
**Lines:** 655-662

```php
<?php if (!empty($errors)): ?>
    <div class="alert alert-error">  <!-- ← RED ERROR BOX -->
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
```

**CSS Styling (Lines 1054-1058):**
```css
.alert-error { 
    background-color: #ffebee;  /* Light red/pink background */
    border: 1px solid #f44336;   /* Red border */
    color: #d32f2f;              /* Dark red text */
}
```

---

## 🔍 **How to Identify the Exact Error**

### **Step 1: Check Browser Console**
1. Open browser Developer Tools (F12)
2. Go to Console tab
3. Look for JavaScript errors or API call errors
4. Check Network tab for failed requests to `get-payment-details.php`

### **Step 2: Check Error Message Text**
What exact text does the red error show?
- "Missing checkout_session_id"
- "cURL Error: ..."
- "PayMongo API Error: ..."
- "Method not allowed"
- Something else?

### **Step 3: Check Which Page Shows the Error**
- Is it on `multi-seller-payment.php`?
- Is it on `multi-seller-checkout.php`?
- Is it on a different page?

### **Step 4: Check Server Error Logs**
The file logs errors to PHP error log:
- **Line 115:** `error_log("Get Payment Details Error: " . $e->getMessage());`

Check your PHP error log for:
```
Get Payment Details Error: [error message]
```

---

## 🐛 **Common Error Scenarios**

### **Scenario 1: Missing Configuration**
**Error:** "PayMongo API Error: ..." or cURL errors  
**Cause:** PayMongo API keys not configured properly  
**Fix:** Check `paymongo/config.php` and ensure API keys are set

### **Scenario 2: Invalid Checkout Session ID**
**Error:** "PayMongo API Error: [API error message]"  
**Cause:** Checkout session ID doesn't exist or expired  
**Fix:** Ensure valid checkout session ID is passed

### **Scenario 3: Network/Connection Issues**
**Error:** "cURL Error: [connection error]"  
**Cause:** Cannot connect to PayMongo API  
**Fix:** Check internet connection, firewall, or API endpoint

### **Scenario 4: Direct Access**
**Error:** "Method not allowed"  
**Cause:** Accessed `get-payment-details.php` directly in browser (GET request)  
**Fix:** This endpoint only accepts POST requests with JSON body

---

## ✅ **How to Fix**

### **If Error is from API Call:**

1. **Check PayMongo Configuration**
   ```php
   // File: paymongo/config.php
   // Ensure these are set:
   - PAYMONGO_SECRET_KEY
   - PAYMONGO_PUBLIC_KEY
   - PAYMONGO_BASE_URL
   ```

2. **Check Request Format**
   - Must be POST request
   - Must include JSON body: `{"checkout_session_id": "..."}`
   - Must have proper headers

3. **Check PayMongo API Status**
   - Verify API keys are valid
   - Check if checkout session exists
   - Ensure API endpoint is accessible

### **If Error is Displayed on Page:**

1. **Check where error is added to `$errors[]` array**
2. **Check JavaScript that calls the endpoint**
3. **Check error handling in frontend code**

---

## 📝 **Next Steps**

**Please provide:**
1. **Exact error message text** you see
2. **Which page** shows the error (URL or page name)
3. **When it appears** (on page load, after clicking something, etc.)
4. **Browser console errors** (if any)

This will help identify the exact source and fix it!

---

**File Created:** 2024  
**Related Files:**
- `paymongo/get-payment-details.php` (API endpoint)
- `paymongo/multi-seller-payment.php` (Payment page)
- `paymongo/multi-seller-checkout.php` (Checkout page)
- `paymongo/config.php` (Configuration)

