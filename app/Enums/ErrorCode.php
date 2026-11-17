<?php

namespace App\Enums;

/**
 * Stable, machine-readable error codes. The apps switch on these, never on the message.
 * Add new codes in the slice that first needs them (see docs/03-api-conventions.md).
 */
enum ErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case TokenExpired = 'TOKEN_EXPIRED';
    case Forbidden = 'FORBIDDEN';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
    case NotFound = 'NOT_FOUND';
    case AccountNotFound = 'ACCOUNT_NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case InvalidState = 'INVALID_STATE';
    case NotConfigured = 'NOT_CONFIGURED';
    case OtpInvalid = 'OTP_INVALID';
    case OtpExpired = 'OTP_EXPIRED';
    case TooManyAttempts = 'TOO_MANY_ATTEMPTS';
    case OutOfCoverage = 'OUT_OF_COVERAGE';
    case NoMatchingProviders = 'NO_MATCHING_PROVIDERS';
    case DuplicateRequest = 'DUPLICATE_REQUEST';
    case SlotUnavailable = 'SLOT_UNAVAILABLE';
    case BlockOverlap = 'BLOCK_OVERLAP';
    case OfferExpired = 'OFFER_EXPIRED';
    case AlreadyBooked = 'ALREADY_BOOKED';
    case WalletExists = 'WALLET_EXISTS';
    case CancellationPending = 'CANCELLATION_PENDING';
    case StartTooEarly = 'START_TOO_EARLY';
    case AlreadyReviewed = 'ALREADY_REVIEWED';
    case AlreadyReported = 'ALREADY_REPORTED';
    case OutOfStock = 'OUT_OF_STOCK'; // Slice 9 cart: quantity above the variant's current stock (03-api-conventions: 422)
    case DeletionPending = 'DELETION_PENDING'; // Slice 12a: she already has a pending account deletion request (409)
    case ServerError = 'SERVER_ERROR';

    // Slice 14b — staff & roles (D165, D168, D169).
    case LastSuperAdmin = 'LAST_SUPER_ADMIN';
    case OwnAccount = 'OWN_ACCOUNT';
    case RoleInUse = 'ROLE_IN_USE';
    case PermissionRestricted = 'PERMISSION_RESTRICTED';

    // Slice 10 — payments (D185, D188, D189).
    case PaymentRequired = 'PAYMENT_REQUIRED';
    case PaymentNotRequired = 'PAYMENT_NOT_REQUIRED';
    case PaymentMethodInvalid = 'PAYMENT_METHOD_INVALID';
    case RefundAmountExceeded = 'REFUND_AMOUNT_EXCEEDED';
    case InvalidSignature = 'INVALID_SIGNATURE';
    // Slice 9b — store checkout and orders (slice-9b-contract.md §9).
    case CartEmpty = 'CART_EMPTY';
    case CartHasIssues = 'CART_HAS_ISSUES';
    case PromoCodeInvalid = 'PROMO_CODE_INVALID';
    case PriceChanged = 'PRICE_CHANGED';
    case CarrierFailed = 'CARRIER_FAILED';

    public function status(): int
    {
        return match ($this) {
            self::ValidationFailed, self::OtpInvalid, self::OtpExpired, self::OutOfCoverage, self::NoMatchingProviders, self::OutOfStock => 422,
            self::Unauthenticated, self::TokenExpired => 401,
            self::Forbidden, self::AccountSuspended => 403,
            self::NotFound, self::AccountNotFound => 404,
            self::MethodNotAllowed => 405,
            self::InvalidState, self::NotConfigured, self::DuplicateRequest, self::SlotUnavailable, self::BlockOverlap, self::OfferExpired, self::AlreadyBooked, self::WalletExists, self::CancellationPending, self::StartTooEarly, self::AlreadyReviewed, self::AlreadyReported, self::DeletionPending => 409,
            self::TooManyAttempts => 429,
            self::ServerError => 500,
            self::LastSuperAdmin, self::OwnAccount, self::RoleInUse => 409, // Slice 14b
            self::PermissionRestricted => 422, // Slice 14b
            self::PaymentRequired, self::PaymentNotRequired => 409, // Slice 10
            self::PaymentMethodInvalid, self::RefundAmountExceeded => 422, // Slice 10
            self::InvalidSignature => 401, // Slice 10
            self::CartEmpty, self::PromoCodeInvalid => 422, // Slice 9b
            self::CartHasIssues, self::PriceChanged, self::CarrierFailed => 409, // Slice 9b
        };
    }

    public function message(): string
    {
        return __('errors.'.$this->value);
    }

    public static function fromStatus(int $status): self
    {
        return match ($status) {
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            405 => self::MethodNotAllowed,
            409 => self::InvalidState,
            422 => self::ValidationFailed,
            429 => self::TooManyAttempts,
            default => self::ServerError,
        };
    }
}
