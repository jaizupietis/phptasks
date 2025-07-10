<?php
/**
 * File Upload Handler
 */

class FileUploader {
    private $uploadPath;
    private $allowedTypes;
    private $maxFileSize;
    
    public function __construct() {
        $this->uploadPath = ROOT_PATH . '/uploads/';
        $this->allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt'];
        $this->maxFileSize = 10 * 1024 * 1024; // 10MB
    }
    
    public function uploadTaskAttachment($taskId, $file) {
        if (!$this->validateFile($file)) {
            return ['success' => false, 'error' => 'Invalid file'];
        }
        
        $uploadDir = $this->uploadPath . 'tasks/' . $taskId . '/';
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = uniqid() . '_' . time() . '.' . $fileExtension;
        $filepath = $uploadDir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            return [
                'success' => true,
                'filename' => $filename,
                'filepath' => $filepath,
                'original_name' => $file['name'],
                'size' => $file['size']
            ];
        }
        
        return ['success' => false, 'error' => 'Upload failed'];
    }
    
    private function validateFile($file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }
        
        if ($file['size'] > $this->maxFileSize) {
            return false;
        }
        
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        return in_array($fileExtension, $this->allowedTypes);
    }
    
    public function deleteFile($filepath) {
        if (file_exists($filepath) && is_file($filepath)) {
            return unlink($filepath);
        }
        return false;
    }
}
?>
