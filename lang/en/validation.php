<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'The :attribute field must be accepted.',
    'accepted_if' => 'The :attribute field must be accepted when :other is :value.',
    'active_url' => 'The :attribute field must be a valid URL.',
    'after' => 'The :attribute field must be a date after :date.',
    'after_or_equal' => 'The :attribute field must be a date after or equal to :date.',
    'alpha' => 'The :attribute field must only contain letters.',
    'alpha_dash' => 'The :attribute field must only contain letters, numbers, dashes, and underscores.',
    'alpha_num' => 'The :attribute field must only contain letters and numbers.',
    'any_of' => 'The :attribute field is invalid.',
    'array' => 'The :attribute field must be an array.',
    'ascii' => 'The :attribute field must only contain single-byte alphanumeric characters and symbols.',
    'before' => 'The :attribute field must be a date before :date.',
    'before_or_equal' => 'The :attribute field must be a date before or equal to :date.',
    'between' => [
        'array' => 'The :attribute field must have between :min and :max items.',
        'file' => 'The :attribute field must be between :min and :max kilobytes.',
        'numeric' => 'The :attribute field must be between :min and :max.',
        'string' => 'The :attribute field must be between :min and :max characters.',
    ],
    'boolean' => 'The :attribute field must be true or false.',
    'can' => 'The :attribute field contains an unauthorized value.',
    'confirmed' => 'The :attribute field confirmation does not match.',
    'contains' => 'The :attribute field is missing a required value.',
    'current_password' => 'The password is incorrect.',
    'date' => 'The :attribute field must be a valid date.',
    'date_equals' => 'The :attribute field must be a date equal to :date.',
    'date_format' => 'The :attribute field must match the format :format.',
    'decimal' => 'The :attribute field must have :decimal decimal places.',
    'declined' => 'The :attribute field must be declined.',
    'declined_if' => 'The :attribute field must be declined when :other is :value.',
    'different' => 'The :attribute field and :other must be different.',
    'digits' => 'The :attribute field must be :digits digits.',
    'digits_between' => 'The :attribute field must be between :min and :max digits.',
    'dimensions' => 'The :attribute field has invalid image dimensions.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'doesnt_contain' => 'The :attribute field must not contain any of the following: :values.',
    'doesnt_end_with' => 'The :attribute field must not end with one of the following: :values.',
    'doesnt_start_with' => 'The :attribute field must not start with one of the following: :values.',
    'email' => 'The :attribute field must be a valid email address.',
    'encoding' => 'The :attribute field must be encoded in :encoding.',
    'ends_with' => 'The :attribute field must end with one of the following: :values.',
    'enum' => 'The selected :attribute is invalid.',
    'exists' => 'The selected :attribute is invalid.',
    'extensions' => 'The :attribute field must have one of the following extensions: :values.',
    'file' => 'The :attribute field must be a file.',
    'filled' => 'The :attribute field must have a value.',
    'gt' => [
        'array' => 'The :attribute field must have more than :value items.',
        'file' => 'The :attribute field must be greater than :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than :value.',
        'string' => 'The :attribute field must be greater than :value characters.',
    ],
    'gte' => [
        'array' => 'The :attribute field must have :value items or more.',
        'file' => 'The :attribute field must be greater than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than or equal to :value.',
        'string' => 'The :attribute field must be greater than or equal to :value characters.',
    ],
    'hex_color' => 'The :attribute field must be a valid hexadecimal color.',
    'image' => 'The :attribute field must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'in_array' => 'The :attribute field must exist in :other.',
    'in_array_keys' => 'The :attribute field must contain at least one of the following keys: :values.',
    'integer' => 'The :attribute field must be an integer.',
    'ip' => 'The :attribute field must be a valid IP address.',
    'ipv4' => 'The :attribute field must be a valid IPv4 address.',
    'ipv6' => 'The :attribute field must be a valid IPv6 address.',
    'json' => 'The :attribute field must be a valid JSON string.',
    'list' => 'The :attribute field must be a list.',
    'lowercase' => 'The :attribute field must be lowercase.',
    'lt' => [
        'array' => 'The :attribute field must have less than :value items.',
        'file' => 'The :attribute field must be less than :value kilobytes.',
        'numeric' => 'The :attribute field must be less than :value.',
        'string' => 'The :attribute field must be less than :value characters.',
    ],
    'lte' => [
        'array' => 'The :attribute field must not have more than :value items.',
        'file' => 'The :attribute field must be less than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be less than or equal to :value.',
        'string' => 'The :attribute field must be less than or equal to :value characters.',
    ],
    'mac_address' => 'The :attribute field must be a valid MAC address.',
    'max' => [
        'array' => 'The :attribute field must not have more than :max items.',
        'file' => 'The :attribute field must not be greater than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'max_digits' => 'The :attribute field must not have more than :max digits.',
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'mimetypes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min items.',
        'file' => 'The :attribute field must be at least :min kilobytes.',
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'min_digits' => 'The :attribute field must have at least :min digits.',
    'missing' => 'The :attribute field must be missing.',
    'missing_if' => 'The :attribute field must be missing when :other is :value.',
    'missing_unless' => 'The :attribute field must be missing unless :other is :value.',
    'missing_with' => 'The :attribute field must be missing when :values is present.',
    'missing_with_all' => 'The :attribute field must be missing when :values are present.',
    'multiple_of' => 'The :attribute field must be a multiple of :value.',
    'not_in' => 'The selected :attribute is invalid.',
    'not_regex' => 'The :attribute field format is invalid.',
    'numeric' => 'The :attribute field must be a number.',
    'password' => [
        'letters' => 'The :attribute field must contain at least one letter.',
        'mixed' => 'The :attribute field must contain at least one uppercase and one lowercase letter.',
        'numbers' => 'The :attribute field must contain at least one number.',
        'symbols' => 'The :attribute field must contain at least one symbol.',
        'uncompromised' => 'The given :attribute has appeared in a data leak. Please choose a different :attribute.',
    ],
    'present' => 'The :attribute field must be present.',
    'present_if' => 'The :attribute field must be present when :other is :value.',
    'present_unless' => 'The :attribute field must be present unless :other is :value.',
    'present_with' => 'The :attribute field must be present when :values is present.',
    'present_with_all' => 'The :attribute field must be present when :values are present.',
    'prohibited' => 'The :attribute field is prohibited.',
    'prohibited_if' => 'The :attribute field is prohibited when :other is :value.',
    'prohibited_if_accepted' => 'The :attribute field is prohibited when :other is accepted.',
    'prohibited_if_declined' => 'The :attribute field is prohibited when :other is declined.',
    'prohibited_unless' => 'The :attribute field is prohibited unless :other is in :values.',
    'prohibits' => 'The :attribute field prohibits :other from being present.',
    'regex' => 'The :attribute field format is invalid.',
    'required' => 'The :attribute field is required.',
    'required_array_keys' => 'The :attribute field must contain entries for: :values.',
    'required_if' => 'The :attribute field is required when :other is :value.',
    'required_if_accepted' => 'The :attribute field is required when :other is accepted.',
    'required_if_declined' => 'The :attribute field is required when :other is declined.',
    'required_unless' => 'The :attribute field is required unless :other is in :values.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'required_with_all' => 'The :attribute field is required when :values are present.',
    'required_without' => 'The :attribute field is required when :values is not present.',
    'required_without_all' => 'The :attribute field is required when none of :values are present.',
    'same' => 'The :attribute field must match :other.',
    'size' => [
        'array' => 'The :attribute field must contain :size items.',
        'file' => 'The :attribute field must be :size kilobytes.',
        'numeric' => 'The :attribute field must be :size.',
        'string' => 'The :attribute field must be :size characters.',
    ],
    'starts_with' => 'The :attribute field must start with one of the following: :values.',
    'string' => 'The :attribute field must be a string.',
    'timezone' => 'The :attribute field must be a valid timezone.',
    'unique' => 'The :attribute has already been taken.',
    'uploaded' => 'The :attribute failed to upload.',
    'uppercase' => 'The :attribute field must be uppercase.',
    'url' => 'The :attribute field must be a valid URL.',
    'ulid' => 'The :attribute field must be a valid ULID.',
    'uuid' => 'The :attribute field must be a valid UUID.',
    'offer_total' => 'The offer total must be more than 0 and at most 999,999.99.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    // Project rules
    'saudi_mobile' => 'The :attribute field must be a valid Saudi mobile number.',

    'custom' => [
        // Slice 13 (S13)
        'member_ids' => ['disabled' => 'This team member is disabled. Enable her first or choose another member.'],
        'media_id' => ['unavailable' => 'Choose a file you uploaded for this kind of message that is not used yet.'],
        'offer_id' => ['unavailable' => 'Choose one of your own offers on this request that the client can see.'],
        'block' => [
            'one_form' => 'Send either a date or a start and end time, not both.',
            'form_required' => 'Send a date, or a start and end time.',
            'starts_at_required' => 'Send the start time together with the end time.',
            'ends_at_required' => 'Send the end time together with the start time.',
            'date_past' => 'The date must be today or later.',
            'iso' => 'Use an ISO-8601 time with a time zone, for example 2026-12-06T10:00:00Z.',
            'whole_minutes' => 'Use whole minutes, without seconds.',
            'ends_after_start' => 'The end must be after the start.',
            'ended' => 'This period is already over.',
            'too_long' => 'A block can be at most 366 days long.',
        ],
        'range' => [
            'to_before_from' => 'The end date must be on or after the start date.',
            'too_long' => 'The range can hold at most 62 days.',
            'from_past' => 'The start date must be today or later.',
        ],
        'service_id' => [
            'unavailable' => 'This service is not available.',
            'not_offered' => 'This provider does not offer this service.',
        ],
        'provider_id' => ['unavailable' => 'This provider is not available.'],
        'execution_place' => [
            'not_offered' => 'This provider does not offer the service at the selected place.',
            'no_city' => 'This provider cannot receive requests at her location yet.',
            'open_event_only' => 'An open request is only possible at the event location.',
        ],
        'address_id' => [
            'required' => 'Choose a saved address or enter the event address.',
            'mine' => 'The selected address was not found.',
            'pin' => 'The selected address has no location pin. Add a pin to the address first.',
            'provider_location' => 'No address is needed when the service is at the provider\'s location.',
        ],
        'address' => ['prohibited_with_id' => 'Send either a saved address or a new address, not both.', 'outside_city' => 'This location is outside the selected city. Choose a place in the city.'],
        'start_time' => ['future' => 'The event start must be in the future.'],
        'guests_count' => [
            'hidden' => 'This service does not ask for the number of guests.',
            'required' => 'Enter the number of guests.',
        ],
        'addon_ids' => [
            'unavailable' => 'One of the selected add-ons is not available for this service.',
            'not_offered' => 'This provider does not offer one of the selected add-ons with this service.',
        ],
        'media_ids' => ['unavailable' => 'This file cannot be attached. Upload it again.'],
        // Slice 24 (S24): requests from a package.
        'package_id' => [
            'unavailable' => 'This package is not available any more.',
            'service' => 'This service is not part of the package.',
        ],
        'execution_places' => [
            'required' => 'Choose at least one place where you deliver this service: at the client\'s event location or at your location.',
            'last' => 'At least one place to deliver this service must stay on. Turn on the other place first.',
        ],
        'confirm' => [
            'accepted_if' => 'Confirm that you deliver this service at the client\'s event location before turning it on.',
        ],
        'coverage_city_ids.*' => [
            'exists' => 'One of the selected cities is not available.',
            'distinct' => 'The same city was selected more than once.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [
        'explanation' => 'explanation',
        'media_ids' => 'attachments',
        'at_provider_location' => 'service at your location',
        'execution_places' => 'service places',
        'coverage_city_ids.*' => 'selected city',
        'at_event_location' => 'service at the event location',
        'confirm' => 'confirmation of service at the event location',
        'display_name' => 'business name',
        'legal_name' => 'legal name',
        'representative_name' => 'representative name',
        'representative_title' => 'representative title',
        'service_ids' => 'services',
        'coverage_city_ids' => 'cities served',
        'document_type_id' => 'document type',
        'media_id' => 'file',
        'photos' => 'work photos',
        'started_year' => 'year started',
        'account_type' => 'account type',
        'items' => 'items to complete',
        'note_to_provider' => 'note to the provider',
        'current_phone' => 'current mobile number',
        'purpose' => 'purpose',
        'app' => 'app',
        'registration_token' => 'registration token',
        'terms_accepted' => 'terms',
        'fcm_token' => 'notification token',
        'preferences' => 'preferences',
        'phone' => 'mobile number',
        'city_id' => 'city',
        'birth_date' => 'birth date',
        'code' => 'verification code',
        'context' => 'attachment type',
        // Slice 13 (S13)
        'team_title' => 'role',
        'team_name' => 'name',
        'member_ids' => 'team members',
        'photo_media_id' => 'photo',
    ],

];
