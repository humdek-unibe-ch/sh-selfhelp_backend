<?php

namespace App\Service\Action;

/**
 * Centralized action-config key definitions used by the scheduling runtime.
 *
 * Keeping config keys in one place reduces coupling between admin JSON payloads,
 * runtime orchestration, and test fixtures.
 */
final class ActionConfig
{
    public const CONDITION = 'condition';
    public const JSON_LOGIC = 'jsonLogic';
    public const BLOCKS = 'blocks';
    public const JOBS = 'jobs';
    public const JOB_NAME = 'job_name';
    public const JOB_TYPE = 'job_type';
    public const JOB_ADD_REMOVE_GROUPS = 'job_add_remove_groups';
    public const SCHEDULE_TIME = 'schedule_time';
    public const NOTIFICATION = 'notification';
    public const REMINDERS = 'reminders';
    public const REMINDER_FORM_ID = 'reminder_form_id';
    public const ON_JOB_EXECUTE = 'on_job_execute';
    public const NOTIFICATION_TYPES = 'notification_types';
    public const RECIPIENT = 'recipient';
    public const SUBJECT = 'subject';
    public const BODY = 'body';
    public const ATTACHMENTS = 'attachments';
    public const REDIRECT_URL = 'redirect_url';
    public const FROM_EMAIL = 'from_email';
    public const FROM_NAME = 'from_name';
    public const REPLY_TO = 'reply_to';
    public const VALID = 'valid';
    public const VALID_TYPE = 'valid_type';
    public const PARENT_JOB_TYPE_HIDDEN = 'parent_job_type_hidden';

    public const TARGET_GROUPS = 'target_groups';
    public const SELECTED_TARGET_GROUPS = 'selected_target_groups';
    public const OVERWRITE_VARIABLES = 'overwrite_variables';
    public const SELECTED_OVERWRITE_VARIABLES = 'selected_overwrite_variables';
    public const OVERWRITE_IMPERSONATE_USER_CODE = 'impersonate_user_code';

    public const CLEAR_EXISTING_JOBS_FOR_ACTION = 'clear_existing_jobs_for_action';
    public const CLEAR_EXISTING_JOBS_FOR_RECORD_AND_ACTION = 'clear_existing_jobs_for_record_and_action';

    public const RANDOMIZE = 'randomize';
    public const RANDOMIZER = 'randomizer';
    public const RANDOMIZER_EVEN_PRESENTATION = 'even_presentation';
    public const RANDOMIZER_RANDOM_ELEMENTS = 'random_elements';
    public const RANDOMIZATION_COUNT = 'randomization_count';

    public const REPEAT = 'repeat';
    public const REPEATER = 'repeater';
    public const REPEAT_UNTIL_DATE = 'repeat_until_date';
    public const REPEATER_UNTIL_DATE = 'repeater_until_date';

    public const OCCURRENCES = 'occurrences';
    public const FREQUENCY = 'frequency';
    public const DAYS_OF_WEEK = 'daysOfWeek';
    public const DAYS_OF_MONTH = 'daysOfMonth';

    public const DEADLINE = 'deadline';
    public const SCHEDULE_AT = 'schedule_at';
    public const REPEAT_EVERY = 'repeat_every';

    public const JOB_SCHEDULE_TYPES = 'job_schedule_types';
    public const SEND_AFTER = 'send_after';
    public const SEND_AFTER_TYPE = 'send_after_type';
    public const SEND_ON = 'send_on';
    public const SEND_ON_DAY = 'send_on_day';
    public const SEND_ON_DAY_AT = 'send_on_day_at';
    public const CUSTOM_TIME = 'custom_time';

    public const JOB_TYPE_ADD_GROUP = 'add_group';
    public const JOB_TYPE_REMOVE_GROUP = 'remove_group';
    public const JOB_TYPE_NOTIFICATION = 'notification';
    public const JOB_TYPE_NOTIFICATION_WITH_REMINDER = 'notification_with_reminder';
    public const JOB_TYPE_NOTIFICATION_WITH_REMINDER_FOR_DIARY = 'notification_with_reminder_for_diary';

    private function __construct()
    {
    }
}
