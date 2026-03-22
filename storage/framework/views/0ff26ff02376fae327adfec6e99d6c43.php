<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 40px auto; padding: 20px;">

    <h2 style="color: #1d4ed8;">Notification Digest — NostosEMR</h2>

    <p>Hi <?php echo e($recipient->first_name); ?>,</p>

    <p>
        You have <strong><?php echo e($count); ?> <?php echo e($count === 1 ? 'new notification' : 'new notifications'); ?></strong>
        waiting for you in NostosEMR.
        Please log in to review them.
    </p>

    <p style="margin: 24px 0;">
        <a href="<?php echo e(config('app.url')); ?>"
           style="background: #1d4ed8; color: #fff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: bold;">
            Log In to NostosEMR
        </a>
    </p>

    <p style="color: #6b7280; font-size: 0.85em;">
        This email contains no patient or clinical information for HIPAA compliance.
        Digest emails are sent every 2 hours when you have unread notifications.
    </p>

    <p>— NostosEMR System</p>

</body>
</html>
<?php /**PATH /var/www/html/resources/views/emails/digest_notification.blade.php ENDPATH**/ ?>