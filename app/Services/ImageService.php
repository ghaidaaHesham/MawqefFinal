<?php

namespace App\Services;

use App\Models\imageUser;
use Illuminate\Http\UploadedFile;

class ImageService
{
    public function uploadUserImage(UploadedFile $image, int $userId, string $userName): imageUser
    {
        $imageName = $this->generateImageName($image);
        $path = $image->storeAs('users', $imageName, 'public');

        return imageUser::create([
            'path' => $path,
            'title' => $userName . '_profile_' . time(),
            'imageable_type' => 'App\Models\User',
            'imageable_id' => $userId,
        ]);
    }

    private function generateImageName(UploadedFile $image): string
    {
        $hash = hash('sha256', uniqid('', true) . microtime(true));
        $extension = strtolower($image->getClientOriginalExtension());

        return substr($hash, 0, 32) . '.' . $extension;
    }
}