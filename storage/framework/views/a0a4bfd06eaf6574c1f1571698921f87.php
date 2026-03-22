<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 40px auto; padding: 20px;">

    <h2 style="color: #1d4ed8;">New Notification — NostosEMR</h2>

    <p>Hi <?php echo e($recipient->first_name); ?>,</p>

    <p>
        You have a new notification waiting for you in NostosEMR.
        Please log in to view it.
    </p>

    <p style="margin: 24px 0;">
        <a href="<?php echo e(config('app.url')); ?>"
           style="background: #1d4ed8; color: #fff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: bold;">
            Log In to NostosEMR
        </a>
    </p>

    <p style="color: #6b7280; font-size: 0.85em;">
        This email contains no patient or clinical information for HIPAA compliance.
    </p>

    <p>— NostosEMR System</p>

</body>
</html>
<?php /**PATH /var/www/html/resources/views/emails/notification.blade.php ENDPATH**/ ?>