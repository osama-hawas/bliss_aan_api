<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    $basePath = __DIR__ . '/api/v1';
    
    if (file_exists($basePath)) {
        $directory = new RecursiveDirectoryIterator($basePath);
        $iterator = new RecursiveIteratorIterator($directory);
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filePath = $file->getPathname();
                
                // Get path relative to routes/api/v1
                $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $filePath);
                $dirPath = dirname($relativePath);
                
                if ($dirPath !== '.') {
                    // Convert backslashes (Windows) to forward slashes for URLs
                    $prefix = str_replace(DIRECTORY_SEPARATOR, '/', $dirPath);
                    
                    Route::prefix($prefix)->group(function () use ($filePath) {
                        require $filePath;
                    });
                } else {
                    // File is directly in routes/api/v1
                    require $filePath;
                }
            }
        }
    }
});
