<!DOCTYPE html>
<html lang="it" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">

    <title><?php echo e($title ?? config('app.name', 'Easy Lab')); ?></title>

    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
</head>
<body class="h-full bg-neutral-50 font-sans text-neutral-800 antialiased">
    <?php echo e($slot); ?>

</body>
</html>
<?php /**PATH /Users/marcopappalardo/Herd/easylab/resources/views/components/guest-layout.blade.php ENDPATH**/ ?>