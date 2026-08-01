<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use  App\Http\Controllers\Auth;
use App\Http\Controllers\ShamCashController;
use  App\Http\Controllers\SettingsController;
use  App\Http\Controllers\ProfileController ;
use App\Http\Controllers\ParkingSpotController;
use App\Http\Controllers\AddressController ;
use App\Http\Controllers\OTPController;

use App\Services\Sms\TraccarSmsService;


// Route لإرسال OTP
Route::post('send-otp', [OTPController::class, 'sendOtp']);
Route::post('resend-otp', [OTPController::class, 'resendOtp']);
Route::post('verify-otp', [OTPController::class, 'verifyOtp']);
Route::post('reset-password', [OTPController::class, 'resetPassword']);

Route::get('test-traccar-connection', function (TraccarSmsService $sms) {
    $result = $sms->testConnection();
    return response()->json($result);
});
/*
--------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
//register
Route::post('registerUserRenter1',[Auth::class,'registerUserRenter']);
Route::post('registerUserOwner1',[Auth::class,'registerUserOwner']);

//login
Route::post('loginUser1',[Auth::class,'loginUser']);






Route::middleware('auth:sanctum')->group( function () {

  






Route::post('logoutUser1',[Auth::class,'logoutUser']);

 Route::post('storeParking1', [ParkingSpotController::class, 'storeParking']);
Route::get('myParkingSpots1',[ParkingSpotController::class,'myParkingSpots']);
Route::get('myParkingSpots0',[ParkingSpotController::class,'myInactiveParkingSpots']);

Route::get('getActiveParkingSpots',[ParkingSpotController::class,'getAllActiveParkingSpots']);
Route::get('getInactiveParkingSpots',[ParkingSpotController::class,'getInactiveParkingSpots']);


Route::get('getParkingSpotsByPrice',[ParkingSpotController::class,'getParkingSpotsByPrice']);
Route::post('toggleActive/{id}',[ParkingSpotController::class,'toggleActive']);
Route::get('show/{id}',[ParkingSpotController::class,'show']);
Route::get('getParkingOwnerDetails/{id}',[ParkingSpotController::class,'getParkingOwnerDetails']);

Route::post('getNearestParkingSpots',[ParkingSpotController::class,'getNearestParkingSpots']);

Route::get('getAvailableParkingSpots',[ParkingSpotController::class,'getAvailableParkingSpots']);

Route::get('getUnavailableParkingSpots',[ParkingSpotController::class,'getUnavailableParkingSpots']);

//ProfileController

Route::post('completeProfile1',[ProfileController ::class,'completeProfile']);
Route::post('updateVehicle1',[ProfileController ::class,'updateVehicle']);
Route::post('updateAddress1',[ProfileController ::class,'updateAddress']);
Route::get('getProfile',[ProfileController ::class,'getProfile']);
Route::get('deleteVehicle',[ProfileController ::class,'deleteVehicle']);

Route::post('uploadIdentityDocuments1',[ProfileController ::class,'uploadIdentityDocuments']);
Route::get('getIdentityDocuments1',[ProfileController ::class,'getIdentityDocuments']);
Route::get('deleteIdentityDocuments1',[ProfileController ::class,'deleteIdentityDocuments']);

//SettingsController



Route::post('updateLanguage1',[SettingsController::class,'updateLanguage']);
Route::post('updateProfile1',[SettingsController::class,'updateProfile']);

Route::post('changePassword1',[SettingsController::class,'changePassword']);

Route::post('updateProfileImage',[SettingsController::class,'updateProfileImage']);
Route::get('deleteProfileImage',[SettingsController::class,'deleteProfileImage']);


//ShamCashController 

 Route::post('storeShamCash1', [ShamCashController::class, 'storeShamCash']);
 Route::get('getShamCash', [ShamCashController::class, 'getShamCash']);
    
 Route::post('updateShamCash', [ShamCashController::class, 'updateShamCash']);
    
  //AddressController 

Route::post('storeAddressOwner',[AddressController ::class,'storeAddressOwner']);

Route::get('getAddress',[AddressController ::class,'getAddress']);

Route::post('updateAddress',[AddressController ::class,'updateAddress']);
Route::get('deleteAddress',[AddressController ::class,'deleteAddress']);





});