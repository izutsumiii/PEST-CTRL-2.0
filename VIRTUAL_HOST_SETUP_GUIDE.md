# Virtual Host Setup Guide for XAMPP

## Step 1: Edit XAMPP's httpd-vhosts.conf

1. Open `C:\xampp\apache\conf\extra\httpd-vhosts.conf` in Notepad++ or any text editor
2. **Run as Administrator** (Right-click → Run as Administrator)
3. Add this configuration at the END of the file:

```apache
# Virtual Host for PEST-CTRL
<VirtualHost *:80>
    ServerName pestctrl.local
    DocumentRoot "C:/xampp/htdocs/GITHUB_PEST-CTRL"
    <Directory "C:/xampp/htdocs/GITHUB_PEST-CTRL">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

4. **Save the file**

## Step 2: Edit Windows Hosts File

1. Open Notepad **as Administrator**:
   - Press `Win + R`
   - Type: `notepad`
   - Right-click Notepad → "Run as administrator"
   - Click "Yes" when prompted

2. In Notepad, go to: `File → Open`
3. Navigate to: `C:\Windows\System32\drivers\etc\`
4. Change file type filter to "All Files (*.*)"
5. Open the file named `hosts` (no extension)
6. Add this line at the END of the file:

```
127.0.0.1    pestctrl.local
```

7. **Save the file**

## Step 3: Enable Virtual Hosts in httpd.conf

1. Open `C:\xampp\apache\conf\httpd.conf` (as Administrator)
2. Find this line (around line 483):
   ```apache
   #Include conf/extra/httpd-vhosts.conf
   ```
3. Remove the `#` to uncomment it:
   ```apache
   Include conf/extra/httpd-vhosts.conf
   ```
4. **Save the file**

## Step 4: Restart Apache

1. Open XAMPP Control Panel
2. **Stop** Apache (if running)
3. **Start** Apache again

## Step 5: Test the Virtual Host

1. Open your browser
2. Go to: `http://pestctrl.local`
3. You should see your application (no `/GITHUB_PEST-CTRL` needed!)

## Step 6: Update ngrok Command

Now when you run ngrok, it will forward to the root:
```bash
ngrok http 80
```

The ngrok URL will be: `https://your-ngrok-url.ngrok-free.dev` (no subdirectory needed!)

## Step 7: Update config.php

After setting up the virtual host, we'll need to update your `paymongo/config.php` to:
- Remove `/GITHUB_PEST-CTRL` from URLs
- Dynamically detect the current ngrok URL
- Handle both local and ngrok URLs properly

## Troubleshooting

### If you get "Access Denied" error:
- Make sure you edited `httpd-vhosts.conf` and `hosts` file as Administrator
- Check that the DocumentRoot path is correct (use forward slashes `/` not backslashes `\`)
- Make sure `AllowOverride All` is set in the VirtualHost config

### If pestctrl.local doesn't work:
- Make sure you saved the hosts file
- Try flushing DNS: Open Command Prompt as Admin and run: `ipconfig /flushdns`
- Try accessing: `http://127.0.0.1` (should still work)

### If Apache won't start:
- Check XAMPP error logs: `C:\xampp\apache\logs\error.log`
- Make sure port 80 is not being used by another application
- Verify the syntax in `httpd-vhosts.conf` is correct

