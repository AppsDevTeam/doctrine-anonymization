<?php

declare(strict_types=1);

namespace ADT\DoctrineAnonymization;

/**
 * Ready-made set of common, domain-neutral personal data types.
 *
 * Use it directly, or take it as a template for your own enum when you need
 * domain-specific types (see {@see AnonymizationTypeInterface}). Both can be mixed
 * - {@see Attributes\Anonymize} accepts any implementation of the interface.
 *
 * The defaults below anonymize identity, contact details, precise location,
 * network identifiers, free text and secrets, while keeping coarse location
 * (city, ZIP) and company/billing identifiers readable, because those are
 * typically needed as reporting dimensions. Override by writing your own enum.
 */
enum AnonymizationType: string implements AnonymizationTypeInterface
{
	// Identity
	case FULL_NAME = 'full_name';
	case FIRST_NAME = 'first_name';
	case LAST_NAME = 'last_name';
	case TITLE = 'title';
	case INITIALS = 'initials';
	case SALUTATION = 'salutation';
	case DATE_OF_BIRTH = 'date_of_birth';
	case GENDER = 'gender';

	// Contact
	case EMAIL = 'email';
	case PHONE = 'phone';
	case STREET = 'street';
	case HOUSE_NUMBER = 'house_number';
	case ZIP = 'zip';
	case CITY = 'city';
	case GPS_LATITUDE = 'gps_latitude';
	case GPS_LONGITUDE = 'gps_longitude';
	case SOCIAL_URL = 'social_url';

	// Free text (may contain names, health details, anything)
	case FREE_TEXT = 'free_text';
	case NOTE = 'note';

	// Network identifiers
	case IP_ADDRESS = 'ip_address';
	case USER_AGENT = 'user_agent';

	// Secrets
	case SECRET = 'secret';
	case BANK_ACCOUNT = 'bank_account';

	// Company / billing
	case COMPANY_NAME = 'company_name';
	case COMPANY_ID = 'company_id';
	case VAT_ID = 'vat_id';

	public function key(): string
	{
		return $this->value;
	}

	public function isAnonymized(): bool
	{
		return match ($this) {
			// Kept readable: coarse location is useful for regional reports and
			// company/billing identifiers are usually public register data.
			self::CITY, self::ZIP, self::GENDER, self::TITLE,
			self::COMPANY_NAME, self::COMPANY_ID, self::VAT_ID
				=> false,

			default
				=> true,
		};
	}

	public function alwaysAnonymize(): bool
	{
		return $this === self::SECRET || $this === self::BANK_ACCOUNT;
	}

	public function isDirectIdentifier(): bool
	{
		return match ($this) {
			self::FULL_NAME, self::FIRST_NAME, self::LAST_NAME, self::INITIALS,
			self::EMAIL, self::PHONE, self::SECRET, self::BANK_ACCOUNT
				=> true,

			default
				=> false,
		};
	}
}
