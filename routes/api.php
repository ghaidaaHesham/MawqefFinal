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
use App\Http\Controllers\ComplaintController;
use Illuminate\Support\Facades\Log;
use App\Services\Sms\TraccarSmsService;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ShamAdminAndOwnerController;
use App\Http\Controllers\PaymentAmountController;
use App\Http\Controllers\ShamPayOwnerController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Routes العامة (بدون توكن)
Route::post('send-otp', [OTPController::class, 'sendOtp']);
Route::post('resend-otp', [OTPController::class, 'resendOtp']);
Route::post('verify-otp', [OTPController::class, 'verifyOtp']);
Route::post('reset-password', [OtpController::class, 'resetPassword']);

// Routes للاختبار
Route::get('test-traccar', [OTPController::class, 'testTraccar']);
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

//auth

Route::middleware('throttle:register')->group(function () {
    Route::post('registerUserRenter1', [Auth::class, 'registerUserRenter']);
    Route::post('registerUserOwner1', [Auth::class, 'registerUserOwner']);
});

Route::post('loginUser1', [Auth::class, 'loginUser'])
    ->middleware('throttle:login');




//otp
Route::middleware('throttle:otp-request')->group(function () {
    Route::post('request-otp', [OtpController::class, 'request']);
});

Route::middleware('throttle:otp-verify')->group(function () {
    Route::post('verify-otp', [OtpController::class, 'verify']);
});

Route::post('reset-password', [OtpController::class, 'resetPassword']);






Route::middleware('auth:sanctum')->group( function () {

  




   Route::post('logoutUser1', [Auth::class, 'logoutUser']);


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
    Route::get('getShamCash1', [ShamCashController::class, 'getShamCash']);
    Route::get('getShamCashByBooking/{bookingId}', [ShamCashController::class, 'getShamCashByBooking']);
    Route::post('updateShamCash1/{id}', [ShamCashController::class, 'updateShamCash']);
    Route::delete('deleteShamCash1', [ShamCashController::class, 'deleteShamCash']);


  //AddressController 

Route::post('storeAddressOwner',[AddressController ::class,'storeAddressOwner']);

Route::get('getAddress',[AddressController ::class,'getAddress']);

Route::post('updateAddress',[AddressController ::class,'updateAddress']);
Route::get('deleteAddress',[AddressController ::class,'deleteAddress']);




//BookingController

Route::post('storeBooking1',[BookingController ::class,'storeBooking']);
Route::get('showBooking/{id}',[BookingController ::class,'showBooking']);
Route::get('myBookings',[BookingController ::class,'myBookings']);
Route::get('spotBookings/{id}',[BookingController ::class,'spotBookings']);
Route::post('updateBookingStatus/{id}',[BookingController ::class,'updateBookingStatus']);
// طلب إلغاء الحجز (من المستأجر)
Route::post('bookings/{id}/request-cancellation', [BookingController::class, 'requestCancellation']);

// الموافقة/الرفض (من صاحب الموقف)
Route::post('bookings/{id}/respond-cancellation', [BookingController::class, 'respondToCancellation']);

//shamcashAdminAndOwner

// routes/api.php




    // إضافة معلومات الشام كاش (للـ Admin أو Owner)
    Route::post('/store', [ShamAdminAndOwnerController::class, 'store']);
    
    // عرض معلومات الشام كاش للمستخدم الحالي
    Route::get('/getMySham', [ShamAdminAndOwnerController::class, 'getMySham']);
    
    // عرض معلومات الشام كاش لمستخدم محدد (للمدير فقط)
    Route::get('/getShamByUser/{userId}', [ShamAdminAndOwnerController::class, 'getShamByUser']);
    
    // عرض جميع معلومات الشام كاش (للمدير فقط)
    Route::get('/getAllSham', [ShamAdminAndOwnerController::class, 'getAllSham']);
    
    // تحديث معلومات الشام كاش
    Route::post('/update', [ShamAdminAndOwnerController::class, 'update']);
    



//ShamCashController

// إضافة معلومات الشام كاش مع الحجز
    Route::post('/payToOwner', [ShamCashController::class, 'store']);
    
    // عرض معلومات الشام كاش لحجز معين
    Route::get('/getByBooking/{bookingId}', [ShamCashController::class, 'getByBooking']);



    //PaymentAmountController



   

    Route::get('/indexx', [PaymentAmountController::class, 'index']);
    
    
    Route::post('/updatee/{id}', [PaymentAmountController::class, 'update']);







    //ShamPayOwnerController
     // دفع صاحب الموقف
    Route::post('/Storeshampayowner', [ShamPayOwnerController::class, 'store']);
    
    // عرض مدفوعات صاحب الموقف الحالي
    Route::get('/showmypayments', [ShamPayOwnerController::class, 'getMyPayments']);
    
    // عرض معلومات الدفع لموقف معين
    Route::get('/showpayowner/{parkingId}', [ShamPayOwnerController::class, 'getByParking']);


    Route::get('/showAllpayments', [ShamPayOwnerController::class, 'getAllPayments']);


    //otp
       Route::post('change-password', [OtpController::class, 'changePassword']);



//ComplaintController
       // ===== الشكاوى =====
    Route::post('complaints', [ComplaintController::class, 'store']);
    Route::get('complaints/my', [ComplaintController::class, 'myComplaints']);
    Route::get('complaints/{id}', [ComplaintController::class, 'show']);

    // ===== للإدمن فقط =====
    Route::get('admin/complaints', [ComplaintController::class, 'index']);
    Route::post('admin/complaints/{id}/respond', [ComplaintController::class, 'adminRespond']);
    Route::post('admin/complaints/{id}/status', [ComplaintController::class, 'updateStatus']);

    // ===== الإشعارات =====
    Route::get('notifications', [ComplaintController::class, 'myNotifications']);
    Route::post('notifications/mark-as-read', [ComplaintController::class, 'markAsRead']);





});