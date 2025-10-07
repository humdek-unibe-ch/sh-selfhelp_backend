delete from fields
where id > 233;

-- Create 'mantine' style group for Mantine-specific components
INSERT IGNORE INTO `styleGroup` (`id`, `name`, `description`, `position`) VALUES (NULL, 'mantine', 'Mantine UI components for modern web interfaces', 10);

-- ===========================================
-- STYLES.SQL - MANTINE COMPONENTS DEFINITION
-- ===========================================
-- EXECUTION ORDER:
-- 1. Field Types
-- 2. Fields
-- 3. Styles and Styles_Fields
-- ===========================================

-- Remove not needed field type `type` from `button` style
DELETE FROM styles_fields
WHERE id_fields = get_field_id('type') and id_styles = get_style_id('button');

-- Structure of the config field:
-- export interface IFieldConfig {
--     // Core select functionality
--     multiSelect?: boolean;
--     creatable?: boolean;
--     separator?: string;
--     clearable?: boolean;
--     searchable?: boolean;
--     allowDeselect?: boolean;
--     // Display and behavior
--     placeholder?: string;
--     nothingFoundMessage?: string;
--     description?: string;
--     error?: string;
--     required?: boolean;
--     withAsterisk?: boolean;
--     disabled?: boolean;
--     // Dropdown configuration
--     limit?: number;
--     maxDropdownHeight?: number;
--     hidePickedOptions?: boolean;
--     maxValues?: number;
--     // Styling and layout
--     checkIconPosition?: 'left' | 'right';
--     leftSection?: ReactNode;
--     rightSection?: ReactNode;
--     // Data and options
--     options?: Array<{
--         value: string;
--         text: string;
--         disabled?: boolean;
--         [key: string]: any;
--     }>;
--     apiUrl?: string;
-- }

CALL add_table_column('fields', 'config', 'JSON DEFAULT NULL');

-- ===========================================
-- 1. FIELD TYPES DEFINITIONS
-- ===========================================

-- Add new field type `select`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'select', '1');

-- Add new field type `color-picker`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'color-picker', '2');

-- Add new field type `slider`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'slider', '3');

-- Add new field type `select-icon`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'select-icon', '4');

-- Add new field type `segment`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'segment', '5');

-- Add new field type `textarea`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'textarea', '6');

-- Add new field type `text`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'text', '7');

-- Add new field type `text`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'mantine_spacing_margin', '0');
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'mantine_spacing_margin_padding', '0');

-- ===========================================
-- 2. FIELDS DEFINITIONS (ALL INSERTED FIRST)
-- ==========================================

-- Add unified icon size field (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_icon_size', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "placeholder": "16", "options":[
{"value":"14","text":"Small (14px)"},
{"value":"16","text":"Medium (16px)"},
{"value":"18","text":"Large (18px)"},
{"value":"20","text":"Extra Large (20px)"},
{"value":"24","text":"XL (24px)"},
{"value":"32","text":"XXL (32px)"}
]}');

-- Add unified icon size field (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_spacing_margin_padding', get_field_type_id('mantine_spacing_margin_padding'), 0, null);
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_spacing_margin', get_field_type_id('mantine_spacing_margin'), 0, null);

-- Add unified loop field (reusable across components that support looping)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_loop', get_field_type_id('checkbox'), 0, null);

-- Add unified control size field (reusable across components with controls)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_control_size', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "placeholder": "16", "options":[
{"value":"14","text":"Small (14px)"},
{"value":"16","text":"Medium (16px)"},
{"value":"18","text":"Large (18px)"},
{"value":"20","text":"Extra Large (20px)"},
{"value":"24","text":"XL (24px)"},
{"value":"32","text":"XXL (32px)"}
]}');

-- Add global tooltip field (reusable across all components)

-- Add global border color field (reusable across all components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_border_color', get_field_type_id('color-picker'), 0, '{
  "options": [
    { "value": "gray", "text": "Gray" },
    { "value": "red", "text": "Red" },        
    { "value": "grape", "text": "Grape" },
    { "value": "violet", "text": "Violet" },        
    { "value": "blue", "text": "Blue" },    
    { "value": "cyan", "text": "Cyan" },    
    { "value": "green", "text": "Green" },    
    { "value": "lime", "text": "Lime" },
    { "value": "yellow", "text": "Yellow" },
    { "value": "orange", "text": "Orange" }    
  ]
}');

-- Add global background color field (reusable across all components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_background_color', get_field_type_id('color-picker'), 0, '{
  "options": [
    { "value": "gray", "text": "Gray" },
    { "value": "red", "text": "Red" },        
    { "value": "grape", "text": "Grape" },
    { "value": "violet", "text": "Violet" },        
    { "value": "blue", "text": "Blue" },    
    { "value": "cyan", "text": "Cyan" },    
    { "value": "green", "text": "Green" },    
    { "value": "lime", "text": "Lime" },
    { "value": "yellow", "text": "Yellow" },
    { "value": "orange", "text": "Orange" }    
  ]
}');

-- Add global directional margin fields (reusable across all components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_mt', get_field_type_id('slider'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "none", "options":[
{"value":"xs","text":"Extra Small"},
{"value":"sm","text":"Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value":"xl","text":"Extra Large"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_mb', get_field_type_id('slider'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "none", "options":[
{"value":"xs","text":"Extra Small"},
{"value":"sm","text":"Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value":"xl","text":"Extra Large"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_ml', get_field_type_id('slider'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "none", "options":[
{"value":"xs","text":"Extra Small"},
{"value":"sm","text":"Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value":"xl","text":"Extra Large"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_mr', get_field_type_id('slider'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "none", "options":[
{"value":"xs","text":"Extra Small"},
{"value":"sm","text":"Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value":"xl","text":"Extra Large"}
]}');

-- Add global directional padding fields (reusable across all components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_pt', get_field_type_id('slider'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "none", "options":[
{"value":"xs","text":"Extra Small"},
{"value":"sm","text":"Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value":"xl","text":"Extra Large"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_pb', get_field_type_id('slider'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "none", "options":[
{"value":"xs","text":"Extra Small"},
{"value":"sm","text":"Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value":"xl","text":"Extra Large"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_pl', get_field_type_id('slider'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "none", "options":[
{"value":"xs","text":"Extra Small"},
{"value":"sm","text":"Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value":"xl","text":"Extra Large"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_pr', get_field_type_id('slider'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "none", "options":[
{"value":"xs","text":"Extra Small"},
{"value":"sm","text":"Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value":"xl","text":"Extra Large"}
]}');

-- Add global border style field (reusable across all components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_border', get_field_type_id('checkbox'), 0, null);

-- Add global border size field (reusable across all components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_border_size', get_field_type_id('slider'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "1", "options":[
{"value":"1","text":"1px"},
{"value":"2","text":"2px"},
{"value":"3","text":"3px"},
{"value":"4","text":"4px"},
{"value":"5","text":"5px"}
]}');

-- Add global shadow field (reusable across all components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_shadow', get_field_type_id('slider'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "none", "options":[
{"value":"none","text":"None"},
{"value":"xs","text":"Extra Small"},
{"value":"sm","text":"Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value":"xl","text":"Extra Large"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'readonly', get_field_type_id('checkbox'), 0, null);
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'translatable', get_field_type_id('checkbox'), 0, null);

-- Add global tooltip position field (reusable across all components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_tooltip_position', get_field_type_id('select'), 0, '{"searchable": false, "clearable": true, "options":[
{"value":"top","text":"Top"},
{"value":"bottom","text":"Bottom"},
{"value":"left","text":"Left"},
{"value":"right","text":"Right"},
{"value":"top-start","text":"Top Start"},
{"value":"top-end","text":"Top End"},
{"value":"bottom-start","text":"Bottom Start"},
{"value":"bottom-end","text":"Bottom End"},
{"value":"left-start","text":"Left Start"},
{"value":"left-end","text":"Left End"},
{"value":"right-start","text":"Right Start"},
{"value":"right-end","text":"Right End"}
]}');

-- Core generic fields (used across multiple components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_variant', get_field_type_id('select'), 0, '{"searchable": false, "clearable": false, "options":[
{"value":"filled","text":"Filled"},
{"value":"light","text":"Light"},
{"value":"outline","text":"Outline"},
{"value":"subtle","text":"Subtle"},
{"value":"default","text":"Default"},
{"value":"transparent","text":"Transparent"},
{"value":"white","text":"White"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_color', get_field_type_id('color-picker'), 0, '{
  "options": [
    { "value": "gray", "text": "Gray" },
    { "value": "red", "text": "Red" },
    { "value": "grape", "text": "Grape" },
    { "value": "violet", "text": "Violet" },
    { "value": "blue", "text": "Blue" },
    { "value": "cyan", "text": "Cyan" },
    { "value": "green", "text": "Green" },
    { "value": "lime", "text": "Lime" },
    { "value": "yellow", "text": "Yellow" },
    { "value": "orange", "text": "Orange" }
  ]
}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_size', get_field_type_id('slider'), 0, '{
"options": [
{"value": "xs", "text": "Extra Small"},
{"value": "sm", "text": "Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value": "xl", "text": "Extra Large"}
]
}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_radius', get_field_type_id('slider'), 0, '{
"options": [
{"value": "none", "text": "None"},
{"value": "xs", "text": "Extra Small"},
{"value": "sm", "text": "Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value": "xl", "text": "Extra Large"}
]
}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_left_icon', get_field_type_id('select-icon'), 0, null);
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_right_icon', get_field_type_id('select-icon'), 0, null);

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_orientation', get_field_type_id('segment'), 0, '{"options":[
{"value":"horizontal","text":"Horizontal"},
{"value":"vertical","text":"Vertical"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_color_format', get_field_type_id('segment'), 0, '{"options":[
{"value":"hex","text":"Hex"},
{"value":"rgba","text":"RGBA"},
{"value":"hsla","text":"HSLA"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_numeric_min', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"0","text":"0"},
{"value":"1","text":"1"},
{"value":"10","text":"10"},
{"value":"100","text":"100"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_numeric_max', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"10","text":"10"},
{"value":"100","text":"100"},
{"value":"1000","text":"1000"},
{"value":"10000","text":"10000"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_numeric_step', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"0.1","text":"0.1"},
{"value":"0.5","text":"0.5"},
{"value":"1","text":"1"},
{"value":"5","text":"5"},
{"value":"10","text":"10"}
]}');

-- Layout and spacing fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_width', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"25%","text":"25%"},
{"value":"50%","text":"50%"},
{"value":"75%","text":"75%"},
{"value":"100%","text":"100%"},
{"value":"auto","text":"Auto"},
{"value":"fit-content","text":"Fit Content"},
{"value":"max-content","text":"Max Content"},
{"value":"min-content","text":"Min Content"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_height', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"25%","text":"25%"},
{"value":"50%","text":"50%"},
{"value":"75%","text":"75%"},
{"value":"100%","text":"100%"},
{"value":"auto","text":"Auto"},
{"value":"fit-content","text":"Fit Content"},
{"value":"max-content","text":"Max Content"},
{"value":"min-content","text":"Min Content"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_gap', get_field_type_id('slider'), 0, '{
"options": [
{"value": "0", "text": "None"},
{"value": "xs", "text": "Extra Small"},
{"value": "sm", "text": "Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value": "xl", "text": "Extra Large"}
]
}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_justify', get_field_type_id('select'), 0, '{"searchable": false, "clearable": true, "options":[
{"value":"flex-start","text":"Start"},
{"value":"center","text":"Center"},
{"value":"flex-end","text":"End"},
{"value":"space-between","text":"Space Between"},
{"value":"space-around","text":"Space Around"},
{"value":"space-evenly","text":"Space Evenly"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_align', get_field_type_id('select'), 0, '{"searchable": false, "clearable": true, "options":[
{"value":"flex-start","text":"Start"},
{"value":"center","text":"Center"},
{"value":"flex-end","text":"End"},
{"value":"stretch","text":"Stretch"},
{"value":"baseline","text":"Baseline"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_direction', get_field_type_id('segment'), 0, '{"options":[
{"value":"row","text":"Row"},
{"value":"column","text":"Column"},
{"value":"row-reverse","text":"Row Reverse"},
{"value":"column-reverse","text":"Column Reverse"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_wrap', get_field_type_id('segment'), 0, '{"options":[
{"value":"wrap","text":"Wrap"},
{"value":"nowrap","text":"No Wrap"},
{"value":"wrap-reverse","text":"Wrap Reverse"}
]}');

-- Component-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_fullwidth', get_field_type_id('checkbox'), 0, null);
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_compact', get_field_type_id('checkbox'), 0, null);
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_auto_contrast', get_field_type_id('checkbox'), 0, null);
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'is_link', get_field_type_id('checkbox'), 0, null);
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'use_mantine_style', get_field_type_id('checkbox'), 0, null);

-- Translatable fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_switch_on_label', get_field_type_id('text'), 1, '{"placeholder": "Enter on label"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_switch_off_label', get_field_type_id('text'), 1, '{"placeholder": "Enter off label"}');

-- Blockquote translatable fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'content', get_field_type_id('textarea'), 1, null);
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'cite', get_field_type_id('text'), 1, null);

-- JSON textarea fields (translatable)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_radio_options', get_field_type_id('json'), 1, '{"rows": 5, "placeholder": "Enter JSON array of radio options"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_segmented_control_data', get_field_type_id('textarea'), 1, '{"rows": 3, "placeholder": "Enter JSON array of segmented control options"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_combobox_data', get_field_type_id('textarea'), 1, '{"rows": 3, "placeholder": "Enter JSON array of combobox options"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_multi_select_data', get_field_type_id('textarea'), 1, '{"rows": 3, "placeholder": "Enter JSON array of multi-select options"}');

-- Add mantine_use_input_wrapper field for switch, checkbox, and radio components
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_use_input_wrapper', get_field_type_id('checkbox'), 0, null);

-- Create type_input field for input component
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'type_input', get_field_type_id('select'), 0, '{"searchable": false, "clearable": false, "options": [
{"value": "text", "text": "Text"},
{"value": "email", "text": "Email"},
{"value": "password", "text": "Password"},
{"value": "number", "text": "Number"},
{"value": "checkbox", "text": "Checkbox"},
{"value": "color", "text": "Color"},
{"value": "date", "text": "Date"},
{"value": "time", "text": "Time"},
{"value": "tel", "text": "Telephone"},
{"value": "url", "text": "URL"}
]}');

-- Create options field for select component
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'options', get_field_type_id('json'), 1, '{"rows": 5, "placeholder": "Enter JSON array of select options"}');

-- Create new fields for Progress components
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_progress_striped', get_field_type_id('checkbox'), 0, null),
(NULL, 'mantine_progress_animated', get_field_type_id('checkbox'), 0, null),
(NULL, 'mantine_progress_auto_contrast', get_field_type_id('checkbox'), 0, null),
(NULL, 'mantine_progress_transition_duration', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "placeholder": "200", "options": [{"value": "150", "text": "Fast (150ms)"}, {"value": "200", "text": "Normal (200ms)"}, {"value": "300", "text": "Slow (300ms)"}, {"value": "400", "text": "Very Slow (400ms)"}, {"value": "0", "text": "Instant (0ms)"}]}'),
(NULL, 'mantine_tooltip_label', get_field_type_id('text'), 1, null),
(NULL, 'mantine_tooltip_position', get_field_type_id('option_select'), 0, '{"options": [{"value": "top", "text": "Top"}, {"value": "bottom", "text": "Bottom"}, {"value": "left", "text": "Left"}, {"value": "right", "text": "Right"}, {"value": "top-start", "text": "Top Start"}, {"value": "top-end", "text": "Top End"}, {"value": "bottom-start", "text": "Bottom Start"}, {"value": "bottom-end", "text": "Bottom End"}, {"value": "left-start", "text": "Left Start"}, {"value": "left-end", "text": "Left End"}, {"value": "right-start", "text": "Right Start"}, {"value": "right-end", "text": "Right End"}]}');

-- ===========================================
-- 3. STYLES AND STYLES_FIELDS (EXECUTED LAST)
-- ===========================================

-- Create 'mantine' style group for Mantine-specific components (position updated to avoid conflict with Wrapper)
INSERT IGNORE INTO `styleGroup` (`id`, `name`, `description`, `position`) VALUES (NULL, 'mantine', 'Mantine UI components for modern web interfaces', 15);

-- ===========================================
-- 1. FIELD TYPES DEFINITIONS
-- ===========================================

-- Add new field type `select`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'select', '1');

-- Add new field type `color-picker`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'color-picker', '2');

-- Add new field type `slider`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'slider', '3');

-- Add new field type `select-icon`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'select-icon', '4');

-- Add new field type `segment`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'segment', '5');

-- Add new field type `textarea`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'textarea', '6');

-- Add new field type `text`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'text', '7');

-- ===========================================
-- 2. FIELDS DEFINITIONS (ALL INSERTED FIRST)
-- ===========================================

-- Add new field `mantine-variant` from type `select` based on the mantine button variant
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_variant', get_field_type_id('select'), 0, '{"searchable" : false, "clearable" : false, "options":[{
"value":"filled",
"text":"Filled"
},
{
"value":"light",
"text":"Light"
},
{
"value":"outline",
"text":"Outline"
},
{
"value":"subtle",
"text":"Subtle"
},
{
"value":"default",
"text":"Default"
},
{
"value":"transparent",
"text":"Transparent"
},
{
"value":"white",
"text":"White"
}]}');



-- Add new field type `color-picker`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'color-picker', '2');


-- Add field `mantine-color-picker` from type `color-picker`
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_color', get_field_type_id('color-picker'), 0, '{
  "options": [
    { "value": "gray", "text": "Gray" },
    { "value": "red", "text": "Red" },        
    { "value": "grape", "text": "Grape" },
    { "value": "violet", "text": "Violet" },        
    { "value": "blue", "text": "Blue" },    
    { "value": "cyan", "text": "Cyan" },    
    { "value": "green", "text": "Green" },    
    { "value": "lime", "text": "Lime" },
    { "value": "yellow", "text": "Yellow" },
    { "value": "orange", "text": "Orange" }    
  ]
}
');

-- ===========================================
-- BOX COMPONENT
-- ===========================================

-- Add new style 'box' based on Mantine Box component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'box',
    
    get_style_group_id('mantine'),
    'Mantine Box component as a base for all Mantine components with style props support',
    1
);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('box'), get_field_id('content'), '', 'Set text content over the children. For more information check https://mantine.dev/core/box', 0, 0, 'Content'),
(get_style_id('box'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the Box component. For more information check https://mantine.dev/core/box', 0, 1, 'Use Mantine Style');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('button'), get_field_id('mantine_color'), 'blue', 'Select color for the button. For more information check https://mantine.dev/core/button', 0, 0, 'Color');


-- Add new field type `slider`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'slider', '3');

-- Add field `mantine-slider-size` from type `slider`
-- Remove old mantine_slider_size field definition (replaced by unified mantine_size)

-- Remove old mantine_slider_radius field definition (replaced by unified mantine_radius)

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) 
VALUES (get_style_id('button'), get_field_id('mantine_size'), 'sm', 'Select size for the button. For more information check https://mantine.dev/core/button', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) 
VALUES (get_style_id('button'), get_field_id('mantine_radius'), 'sm', 'Select border radius for the button. For more information check https://mantine.dev/core/button', 0, 0, 'Radius');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_fullwidth', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) 
VALUES (get_style_id('button'), get_field_id('mantine_fullwidth'), '0', 'If `fullWidth`	 prop is set Button will take 100% of parent width', 0, 0, 'Full Width');

INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'select-icon', '4');

-- Add new field type `segment`
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'segment', '5');

-- Use unified icon fields for button
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) 
VALUES (get_style_id('button'), get_field_id('mantine_left_icon'), NULL, '`leftSection` and `rightSection` allow adding icons or any other element to the left and right side of the button. When a section is added, padding on the corresponding side is reduced. Note that `leftSection` and `rightSection` are flipped in RTL mode (`leftSection` is displayed on the right and `rightSection` is displayed on the left).', 0, 0, 'Left Icon');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) 
VALUES (get_style_id('button'), get_field_id('mantine_right_icon'), NULL, '`leftSection` and `rightSection` allow adding icons or any other element to the left and right side of the button. When a section is added, padding on the corresponding side is reduced. Note that `leftSection` and `rightSection` are flipped in RTL mode (`leftSection` is displayed on the right and `rightSection` is displayed on the left).', 0, 0, 'Right Icon');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_compact', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) 
VALUES (get_style_id('button'), get_field_id('mantine_compact'), '0', 'If `compact` prop is set Button will be smaller. Button supports xs – xl and compact-xs – compact-xl sizes. compact sizes have the same font-size as xs – xl but reduced padding and height.', 0, 0, 'Compact');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_auto_contrast', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) 
VALUES (get_style_id('button'), get_field_id('mantine_auto_contrast'), '0', 'If `autoContrast` prop is set Button will automatically adjust the contrast of the button to the background color. For more information check https://mantine.dev/core/button', 0, 0, 'Auto Contrast');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'is_link', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) 
VALUES (get_style_id('button'), get_field_id('is_link'), '0', 'If `isLink` prop is set Button will be a link. For more information check https://mantine.dev/core/button', 0, 0, 'Is Link');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) 
VALUES (get_style_id('button'), get_field_id('disabled'), '0', 'If `disabled` prop is set Button will be disabled. For more information check https://mantine.dev/core/button', 0, 0, 'Disabled');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) 
VALUES (get_style_id('button'), get_field_id('open_in_new_tab'), '0', 'If `openInNewTab` prop is set Button will open in a new tab. For more information check https://mantine.dev/core/button', 0, 0, 'Open in New Tab');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'use_mantine_style', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) 
VALUES (get_style_id('button'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Button will use the Mantine style, otherwise it will be a clear element whcih can be styled with CSS and tailwind CSS classes. For more information check https://mantine.dev/core/button', 0, 1, 'Use Mantine Style');

UPDATE fieldType
SET position = 0
WHERE `name` = 'checkbox';

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) 
VALUES (get_style_id('button'), get_field_id('page_keyword'), '#', 'Select a page keyword to link to. For more information check https://mantine.dev/core/button', 0, 0, 'URL');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('button'), get_field_id('mantine_variant'), 'filled', 'Select a variant for the button. For more information check https://mantine.dev/core/button', 0, 0, 'Variant');

-- Add spacing fields for button (IStyleWithMargin)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('button'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Button component', 0, 0, 'Margin');

DELETE FROM styles_fields
WHERE id_fields = get_field_id('url') and id_styles = get_style_id('button');

-- Create 'mantine' style group for Mantine-specific components (position updated to avoid conflict with Wrapper)
INSERT IGNORE INTO `styleGroup` (`id`, `name`, `description`, `position`) VALUES (NULL, 'mantine', 'Mantine UI components for modern web interfaces', 15);

-- Add new style 'center' based on Mantine Center component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'center',
    
    get_style_group_id('mantine'),
    'Mantine Center component for centering content',
    1
);

-- Add field for inline property (checkbox)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_center_inline', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('center'), get_field_id('mantine_center_inline'), '0', 'If `inline` prop is set, Center will use inline-flex instead of flex display. For more information check https://mantine.dev/core/center', 0, 0, 'Inline');

-- Add generic width field (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_width', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"25%","text":"25%"},
{"value":"50%","text":"50%"},
{"value":"75%","text":"75%"},
{"value":"100%","text":"100%"},
{"value":"auto","text":"Auto"},
{"value":"fit-content","text":"Fit Content"},
{"value":"max-content","text":"Max Content"},
{"value":"min-content","text":"Min Content"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('center'), get_field_id('mantine_width'), NULL, 'Sets the width of the Center component. Common values include percentages, auto, or content-based sizing. For more information check https://mantine.dev/core/center', 0, 0, 'Width');

-- Add generic height field (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_height', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"25%","text":"25%"},
{"value":"50%","text":"50%"},
{"value":"75%","text":"75%"},
{"value":"100%","text":"100%"},
{"value":"auto","text":"Auto"},
{"value":"fit-content","text":"Fit Content"},
{"value":"max-content","text":"Max Content"},
{"value":"min-content","text":"Min Content"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('center'), get_field_id('mantine_height'), NULL, 'Sets the height of the Center component. Common values include percentages, auto, or content-based sizing. For more information check https://mantine.dev/core/center', 0, 0, 'Height');

-- Add generic minimum width field (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_miw', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"0","text":"0"},
{"value":"25%","text":"25%"},
{"value":"50%","text":"50%"},
{"value":"100%","text":"100%"},
{"value":"200px","text":"200px"},
{"value":"300px","text":"300px"},
{"value":"400px","text":"400px"},
{"value":"500px","text":"500px"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('center'), get_field_id('mantine_miw'), NULL, 'Sets the minimum width of the Center component. For more information check https://mantine.dev/core/center', 0, 0, 'Min Width');

-- Add generic minimum height field (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_mih', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"0","text":"0"},
{"value":"25%","text":"25%"},
{"value":"50%","text":"50%"},
{"value":"100%","text":"100%"},
{"value":"200px","text":"200px"},
{"value":"300px","text":"300px"},
{"value":"400px","text":"400px"},
{"value":"500px","text":"500px"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('center'), get_field_id('mantine_mih'), NULL, 'Sets the minimum height of the Center component. For more information check https://mantine.dev/core/center', 0, 0, 'Min Height');

-- Add generic maximum width field (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_maw', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"25%","text":"25%"},
{"value":"50%","text":"50%"},
{"value":"75%","text":"75%"},
{"value":"100%","text":"100%"},
{"value":"200px","text":"200px"},
{"value":"300px","text":"300px"},
{"value":"400px","text":"400px"},
{"value":"500px","text":"500px"},
{"value":"600px","text":"600px"},
{"value":"800px","text":"800px"},
{"value":"1000px","text":"1000px"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('center'), get_field_id('mantine_maw'), NULL, 'Sets the maximum width of the Center component. For more information check https://mantine.dev/core/center', 0, 0, 'Max Width');

-- Add generic maximum height field (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_mah', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"25%","text":"25%"},
{"value":"50%","text":"50%"},
{"value":"75%","text":"75%"},
{"value":"100%","text":"100%"},
{"value":"200px","text":"200px"},
{"value":"300px","text":"300px"},
{"value":"400px","text":"400px"},
{"value":"500px","text":"500px"},
{"value":"600px","text":"600px"},
{"value":"800px","text":"800px"},
{"value":"1000px","text":"1000px"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('center'), get_field_id('mantine_mah'), NULL, 'Sets the maximum height of the Center component. For more information check https://mantine.dev/core/center', 0, 0, 'Max Height');


-- Add new style 'container' based on Mantine Container component (core props only)
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'container',
    
    get_style_group_id('mantine'),
    'Mantine Container component for responsive layout containers',
    1
);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('container'), get_field_id('mantine_size'), NULL, 'Sets the maximum width of the Container component. Choose from predefined responsive breakpoints or enter custom pixel values. For more information check https://mantine.dev/core/container', 0, 0, 'Size');

-- Add fluid property field (core Mantine prop)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_fluid', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('container'), get_field_id('mantine_fluid'), '0', 'If `fluid` prop is set Container will take 100% of parent width, ignoring size prop. For more information check https://mantine.dev/core/container', 0, 0, 'Fluid');

-- Add horizontal padding field (core Mantine prop)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_px', get_field_type_id('slider'), 0, '{
"options": [
{"value": "none", "text": "None"},
{"value": "xs", "text": "Extra Small"},
{"value": "sm", "text": "Small"},
{"value": "md", "text": "Medium"},
{"value": "lg", "text": "Large"},
{"value": "xl", "text": "Extra Large"}
]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('container'), get_field_id('mantine_px'), NULL, 'Sets the horizontal padding of the Container component. Choose from predefined sizes or enter custom values. For more information check https://mantine.dev/core/container', 0, 0, 'Horizontal Padding');

-- Add vertical padding field (core Mantine prop)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_py', get_field_type_id('slider'), 0, '{
"options": [
{"value": "none", "text": "None"},
{"value": "xs", "text": "Extra Small"},
{"value": "sm", "text": "Small"},
{"value": "md", "text": "Medium"},
{"value": "lg", "text": "Large"},
{"value": "xl", "text": "Extra Large"}
]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('container'), get_field_id('mantine_py'), NULL, 'Sets the vertical padding of the Container component. Choose from predefined sizes or enter custom values. For more information check https://mantine.dev/core/container', 0, 0, 'Vertical Padding');

-- NOTE: use_mantine_style field is already a generic field created for the button style
-- and can be reused across ALL components (both Mantine and non-Mantine components)
-- It provides the option to use Mantine styling or fall back to custom CSS/Tailwind classes

-- Add use_mantine_style field for Container (reuse existing generic field)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('container'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Container will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/container', 0, 1, 'Use Mantine Style');

-- Add generic gap field (reusable across components) - use slider
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_gap', get_field_type_id('slider'), 0, '{
	"options": [
		{"value": "0", "text": "None"},
		{"value": "xs", "text": "Extra Small"},
		{"value": "sm", "text": "Small"},
		{"value":"md","text":"Medium"},
		{"value":"lg","text":"Large"},
		{"value": "xl", "text": "Extra Large"}
	]
}');

-- Add generic justify field (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_justify', get_field_type_id('select'), 0, '{"searchable": false, "clearable": true, "options":[
{"value":"flex-start","text":"Start"},
{"value":"center","text":"Center"},
{"value":"flex-end","text":"End"},
{"value":"space-between","text":"Space Between"},
{"value":"space-around","text":"Space Around"},
{"value":"space-evenly","text":"Space Evenly"}
]}');

-- Add generic align field (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_align', get_field_type_id('select'), 0, '{"searchable": false, "clearable": true, "options":[
{"value":"flex-start","text":"Start"},
{"value":"center","text":"Center"},
{"value":"flex-end","text":"End"},
{"value":"stretch","text":"Stretch"},
{"value":"baseline","text":"Baseline"}
]}');

-- Add generic direction field (reusable across components) - use segment type
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_direction', get_field_type_id('segment'), 0, '{"options":[
{"value":"row","text":"Row"},
{"value":"column","text":"Column"},
{"value":"row-reverse","text":"Row Reverse"},
{"value":"column-reverse","text":"Column Reverse"}
]}');

-- Add generic wrap field (reusable across components) - use segment type
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_wrap', get_field_type_id('segment'), 0, '{"options":[
{"value":"wrap","text":"Wrap"},
{"value":"nowrap","text":"No Wrap"},
{"value":"wrap-reverse","text":"Wrap Reverse"}
]}');

-- Add generic breakpoints field (reusable across components) - use slider
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_breakpoints', get_field_type_id('slider'), 0, '{
	"options": [
		{"value": "xs", "text": "Extra Small"},
		{"value": "sm", "text": "Small"},
		{"value":"md","text":"Medium"},
		{"value":"lg","text":"Large"},
		{"value": "xl", "text": "Extra Large"}
	]
}');

-- Add generic columns field (reusable across components) - slider from 1 to 6
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_cols', get_field_type_id('slider'), 0, '{
	"options": [
		{"value": "1", "text": "1"},
		{"value": "2", "text": "2"},
		{"value": "3", "text": "3"},
		{"value": "4", "text": "4"},
		{"value": "5", "text": "5"},
		{"value": "6", "text": "6"}
	]
}');

-- ===========================================
-- FLEX COMPONENT
-- ===========================================

-- Add new style 'flex' based on Mantine Flex component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'flex',
    
    get_style_group_id('mantine'),
    'Mantine Flex component for flexible layouts',
    1
);

-- Add Flex-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('flex'), get_field_id('mantine_gap'), 'sm', 'Sets the gap between flex items. For more information check https://mantine.dev/core/flex', 0, 0, 'Gap');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('flex'), get_field_id('mantine_justify'), NULL, 'Sets the justify-content property. For more information check https://mantine.dev/core/flex', 0, 0, 'Justify');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('flex'), get_field_id('mantine_align'), NULL, 'Sets the align-items property. For more information check https://mantine.dev/core/flex', 0, 0, 'Align Items');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('flex'), get_field_id('mantine_direction'), 'row', 'Sets the flex-direction property. For more information check https://mantine.dev/core/flex', 0, 0, 'Direction');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('flex'), get_field_id('mantine_wrap'), 'nowrap', 'Sets the flex-wrap property. For more information check https://mantine.dev/core/flex', 0, 0, 'Wrap');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('flex'), get_field_id('mantine_width'), NULL, 'Sets the width of the Flex component. For more information check https://mantine.dev/core/flex', 0, 0, 'Width');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('flex'), get_field_id('mantine_height'), NULL, 'Sets the height of the Flex component. For more information check https://mantine.dev/core/flex', 0, 0, 'Height');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('flex'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Flex will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/flex', 0, 1, 'Use Mantine Style');

-- ===========================================
-- GROUP COMPONENT
-- ===========================================

-- Add new style 'group' based on Mantine Group component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'group',
    
    get_style_group_id('mantine'),
    'Mantine Group component for horizontal layouts',
    1
);

-- Add Group-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('group'), get_field_id('mantine_gap'), 'sm', 'Sets the gap between group items. For more information check https://mantine.dev/core/group', 0, 0, 'Gap');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('group'), get_field_id('mantine_justify'), NULL, 'Sets the justify-content property. For more information check https://mantine.dev/core/group', 0, 0, 'Justify');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('group'), get_field_id('mantine_align'), NULL, 'Sets the align-items property. For more information check https://mantine.dev/core/group', 0, 0, 'Align Items');

-- Add Group-specific fields - use segment type
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_group_wrap', get_field_type_id('segment'), 0, '{"options":[
{"value":"0","text":"No Wrap"},
{"value":"1","text":"Wrap"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('group'), get_field_id('mantine_group_wrap'), '0', 'If `wrap` prop is set Group will wrap items to the next line when there is not enough space. For more information check https://mantine.dev/core/group', 0, 0, 'Wrap');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_group_grow', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('group'), get_field_id('mantine_group_grow'), '0', 'If `grow` prop is set Group will take all available space. For more information check https://mantine.dev/core/group', 0, 0, 'Grow');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('group'), get_field_id('mantine_width'), NULL, 'Sets the width of the Group component. For more information check https://mantine.dev/core/group', 0, 0, 'Width');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('group'), get_field_id('mantine_height'), NULL, 'Sets the height of the Group component. For more information check https://mantine.dev/core/group', 0, 0, 'Height');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('group'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Group will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/group', 0, 1, 'Use Mantine Style');

-- ===========================================
-- simple-grid COMPONENT
-- ===========================================

-- Add new style 'simple-grid' based on Mantine simple-grid component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'simple-grid',
    
    get_style_group_id('mantine'),
    'Mantine simple-grid component for responsive grid layouts',
    1
);

-- Add simple-grid-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('simple-grid'), get_field_id('mantine_cols'), '3', 'Sets the number of columns in the grid (1-6). For more information check https://mantine.dev/core/simple-grid', 0, 0, 'Columns');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('simple-grid'), get_field_id('mantine_breakpoints'), NULL, 'Sets responsive breakpoints for different screen sizes. For more information check https://mantine.dev/core/simple-grid', 0, 0, 'Breakpoints');

-- Add vertical spacing field (reusable across components) - use slider
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_vertical_spacing', get_field_type_id('slider'), 0, '{
	"options": [
		{"value": "0", "text": "None"},
		{"value": "xs", "text": "Extra Small"},
		{"value": "sm", "text": "Small"},
		{"value":"md","text":"Medium"},
		{"value":"lg","text":"Large"},
		{"value": "xl", "text": "Extra Large"}
	]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('simple-grid'), get_field_id('mantine_vertical_spacing'), NULL, 'Sets the vertical spacing between grid items. For more information check https://mantine.dev/core/simple-grid', 0, 0, 'Vertical Spacing');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('simple-grid'), get_field_id('mantine_width'), NULL, 'Sets the width of the simple-grid component. For more information check https://mantine.dev/core/simple-grid', 0, 0, 'Width');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('simple-grid'), get_field_id('mantine_height'), NULL, 'Sets the height of the simple-grid component. For more information check https://mantine.dev/core/simple-grid', 0, 0, 'Height');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('simple-grid'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set simple-grid will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/simple-grid', 0, 1, 'Use Mantine Style');

-- ===========================================
-- SPACE COMPONENT
-- ===========================================

-- Add new style 'space' based on Mantine Space component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'space',
    
    get_style_group_id('mantine'),
    'Mantine Space component for adding spacing',
    0
);

-- Add Space-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('space'), get_field_id('mantine_size'), 'sm', 'Sets the size of the space. For more information check https://mantine.dev/core/space', 0, 0, 'Size');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_space_direction', get_field_type_id('segment'), 0, '{"options":[
{"value":"horizontal","text":"Horizontal"},
{"value":"vertical","text":"Vertical"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('space'), get_field_id('mantine_space_direction'), 'vertical', 'If `direction` prop is set Space will add horizontal spacing, otherwise it adds vertical spacing. For more information check https://mantine.dev/core/space', 0, 0, 'Direction');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('space'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Space will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/space', 0, 1, 'Use Mantine Style');

-- ===========================================
-- STACK COMPONENT
-- ===========================================

-- Add new style 'stack' based on Mantine Stack component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'stack',
    
    get_style_group_id('mantine'),
    'Mantine Stack component for vertical layouts',
    1
);

-- Add Stack-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('stack'), get_field_id('mantine_gap'), 'sm', 'Sets the gap between stack items. For more information check https://mantine.dev/core/stack', 0, 0, 'Gap');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('stack'), get_field_id('mantine_justify'), NULL, 'Sets the justify-content property. For more information check https://mantine.dev/core/stack', 0, 0, 'Justify');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('stack'), get_field_id('mantine_align'), NULL, 'Sets the align-items property. For more information check https://mantine.dev/core/stack', 0, 0, 'Align Items');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('stack'), get_field_id('mantine_width'), NULL, 'Sets the width of the Stack component. For more information check https://mantine.dev/core/stack', 0, 0, 'Width');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('stack'), get_field_id('mantine_height'), NULL, 'Sets the height of the Stack component. For more information check https://mantine.dev/core/stack', 0, 0, 'Height');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('stack'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Stack will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/stack', 0, 1, 'Use Mantine Style');

-- ===========================================
-- GRID COMPONENT
-- ===========================================

-- Add new style 'grid' based on Mantine Grid component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'grid',
    
    get_style_group_id('mantine'),
    'Mantine Grid component for responsive 12 columns grid system',
    0
);

-- Add Grid-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid'), get_field_id('mantine_cols'), '12', 'Sets the total number of columns in the grid (default 12). For more information check https://mantine.dev/core/grid', 0, 0, 'Columns');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid'), get_field_id('mantine_gap'), 'sm', 'Sets the gutter (spacing) between grid columns. For more information check https://mantine.dev/core/grid', 0, 0, 'Gutter');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid'), get_field_id('mantine_justify'), NULL, 'Sets the justify-content CSS property for the grid. For more information check https://mantine.dev/core/grid', 0, 0, 'Justify');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid'), get_field_id('mantine_align'), NULL, 'Sets the align-items CSS property for the grid. For more information check https://mantine.dev/core/grid', 0, 0, 'Align');

-- Add grid-specific field for overflow
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_grid_overflow', get_field_type_id('segment'), 0, '{"options":[
{"value":"visible","text":"Visible"},
{"value":"hidden","text":"Hidden"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid'), get_field_id('mantine_grid_overflow'), 'visible', 'Sets the overflow CSS property for the grid container. For more information check https://mantine.dev/core/grid', 0, 0, 'Overflow');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid'), get_field_id('mantine_width'), NULL, 'Sets the width of the Grid component. For more information check https://mantine.dev/core/grid', 0, 0, 'Width');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid'), get_field_id('mantine_height'), NULL, 'Sets the height of the Grid component. For more information check https://mantine.dev/core/grid', 0, 0, 'Height');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Grid will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/grid', 0, 1, 'Use Mantine Style');

-- ===========================================
-- GRID-COLUMN COMPONENT
-- ===========================================

-- Add new style 'grid-column' based on Mantine Grid.Col component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'grid-column',
    
    get_style_group_id('mantine'),
    'Mantine Grid.Col component for grid column with span, offset, and order controls',
    1
);

-- Add Grid.Col-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_grid_span', get_field_type_id('slider'), 0, '{
	"options": [
		{"value": "1", "text": "1"},
		{"value": "2", "text": "2"},
		{"value": "3", "text": "3"},
		{"value": "4", "text": "4"},
		{"value": "5", "text": "5"},
		{"value": "6", "text": "6"},
		{"value": "7", "text": "7"},
		{"value": "8", "text": "8"},
		{"value": "9", "text": "9"},
		{"value": "10", "text": "10"},
		{"value": "11", "text": "11"},
		{"value": "12", "text": "12"},
		{"value": "auto", "text": "Auto"},
		{"value": "content", "text": "Content"}
	]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid-column'), get_field_id('mantine_grid_span'), '1', 'Sets the span (width) of the column. Number from 1-12 or "auto"/"content". For more information check https://mantine.dev/core/grid', 0, 0, 'Span');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_grid_offset', get_field_type_id('slider'), 0, '{
	"options": [
		{"value": "0", "text": "0"},
		{"value": "1", "text": "1"},
		{"value": "2", "text": "2"},
		{"value": "3", "text": "3"},
		{"value": "4", "text": "4"},
		{"value": "5", "text": "5"},
		{"value": "6", "text": "6"},
		{"value": "7", "text": "7"},
		{"value": "8", "text": "8"},
		{"value": "9", "text": "9"},
		{"value": "10", "text": "10"},
		{"value": "11", "text": "11"}
	]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid-column'), get_field_id('mantine_grid_offset'), '0', 'Sets the offset (left margin) of the column. Number from 0-11. For more information check https://mantine.dev/core/grid', 0, 0, 'Offset');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_grid_order', get_field_type_id('slider'), 0, '{
	"options": [
		{"value": "1", "text": "1"},
		{"value": "2", "text": "2"},
		{"value": "3", "text": "3"},
		{"value": "4", "text": "4"},
		{"value": "5", "text": "5"},
		{"value": "6", "text": "6"},
		{"value": "7", "text": "7"},
		{"value": "8", "text": "8"},
		{"value": "9", "text": "9"},
		{"value": "10", "text": "10"},
		{"value": "11", "text": "11"},
		{"value": "12", "text": "12"}
	]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid-column'), get_field_id('mantine_grid_order'), NULL, 'Sets the order of the column for reordering. Number from 1-12. For more information check https://mantine.dev/core/grid', 0, 0, 'Order');

-- Add grid-column specific field for grow
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_grid_grow', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid-column'), get_field_id('mantine_grid_grow'), '0', 'If `grow` prop is set, column will grow to fill the remaining space in the row. For more information check https://mantine.dev/core/grid', 0, 0, 'Grow');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid-column'), get_field_id('mantine_width'), NULL, 'Sets the width of the Grid.Col component. For more information check https://mantine.dev/core/grid', 0, 0, 'Width');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid-column'), get_field_id('mantine_height'), NULL, 'Sets the height of the Grid.Col component. For more information check https://mantine.dev/core/grid', 0, 0, 'Height');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('grid-column'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Grid.Col will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/grid', 0, 1, 'Use Mantine Style');

-- ===========================================
-- TABS COMPONENT
-- ===========================================

-- Add new style 'tabs' based on Mantine Tabs component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'tabs',
    
    get_style_group_id('mantine'),
    'Mantine Tabs component for switching between different views',
    0
);

-- Add Tabs-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_tabs_variant', get_field_type_id('select'), 0, '{"searchable": false, "clearable": false, "options":[
{"value":"default","text":"Default"},
{"value":"outline","text":"Outline"},
{"value":"pills","text":"Pills"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('tabs'), get_field_id('mantine_tabs_variant'), 'default', 'Sets the variant of the tabs. For more information check https://mantine.dev/core/tabs', 0, 0, 'Variant');

-- Use unified orientation field for tabs
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('tabs'), get_field_id('mantine_orientation'), 'horizontal', 'Sets the orientation of the tabs. For more information check https://mantine.dev/core/tabs', 0, 0, 'Orientation');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_tabs_radius', get_field_type_id('slider'), 0, '{
	"options": [
		{"value": "xs", "text": "Extra Small"},
		{"value": "sm", "text": "Small"},
		{"value":"md","text":"Medium"},
		{"value":"lg","text":"Large"},
		{"value": "xl", "text": "Extra Large"}
	]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('tabs'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the tabs. For more information check https://mantine.dev/core/tabs', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('tabs'), get_field_id('mantine_color'), 'blue', 'Sets the color of the tabs. For more information check https://mantine.dev/core/tabs', 0, 0, 'Color');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('tabs'), get_field_id('mantine_width'), NULL, 'Sets the width of the Tabs component. For more information check https://mantine.dev/core/tabs', 0, 0, 'Width');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('tabs'), get_field_id('mantine_height'), NULL, 'Sets the height of the Tabs component. For more information check https://mantine.dev/core/tabs', 0, 0, 'Height');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('tabs'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Tabs will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/tabs', 0, 1, 'Use Mantine Style');

-- ===========================================
-- TAB COMPONENT
-- ===========================================

-- Add new style 'tab' based on Mantine Tabs.Tab component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'tab',
    
    get_style_group_id('mantine'),
    'Mantine Tabs.Tab component for individual tab items within a tabs component. Can contain child components for tab panel content.',
    1
);

-- Add content field for tab label (using existing content field)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('tab'), get_field_id('label'), NULL, 'Sets the label/content of the tab that will be displayed to users. For more information check https://mantine.dev/core/tabs', 0, 0, 'Label');

-- Use unified icon fields for tab
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('tab'), get_field_id('mantine_left_icon'), NULL, 'Sets the left section (icon) of the tab. For more information check https://mantine.dev/core/tabs', 0, 0, 'Left Icon');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('tab'), get_field_id('mantine_right_icon'), NULL, 'Sets the right section (icon) of the tab. For more information check https://mantine.dev/core/tabs', 0, 0, 'Right Icon');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_tab_disabled', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('tab'), get_field_id('mantine_tab_disabled'), '0', 'If `disabled` prop is set, tab will be disabled. For more information check https://mantine.dev/core/tabs', 0, 0, 'Disabled');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('tab'), get_field_id('mantine_width'), NULL, 'Sets the width of the Tabs.Tab component. For more information check https://mantine.dev/core/tabs', 0, 0, 'Width');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('tab'), get_field_id('mantine_height'), NULL, 'Sets the height of the Tabs.Tab component. For more information check https://mantine.dev/core/tabs', 0, 0, 'Height');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('tab'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Tabs.Tab will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/tabs', 0, 1, 'Use Mantine Style');

-- ===========================================
-- STYLE RELATIONSHIPS
-- ===========================================

-- Define that grid-column can ONLY be added inside grid
INSERT IGNORE INTO styles_allowed_relationships (id_parent_style, id_child_style)
SELECT s1.id, s2.id FROM styles s1, styles s2
WHERE s1.name = 'grid' AND s2.name = 'grid-column';

-- ===========================================
-- ASPECT RATIO COMPONENT
-- ===========================================

-- Add new style 'aspect-ratio' based on Mantine AspectRatio component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'aspect-ratio',
    
    get_style_group_id('mantine'),
    'Mantine AspectRatio component for maintaining aspect ratios',
    1
);

-- Add AspectRatio-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_aspect_ratio', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": false, "options":[
{"value":"16/9","text":"16:9"},
{"value":"4/3","text":"4:3"},
{"value":"1/1","text":"1:1"},
{"value":"21/9","text":"21:9"},
{"value":"3/2","text":"3:2"},
{"value":"9/16","text":"9:16"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('aspect-ratio'), get_field_id('mantine_aspect_ratio'), '16/9', 'Sets the aspect ratio of the component. For more information check https://mantine.dev/core/aspect-ratio', 0, 0, 'Ratio');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('aspect-ratio'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set AspectRatio will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/aspect-ratio', 0, 1, 'Use Mantine Style');

-- ===========================================
-- CHIP COMPONENT
-- ===========================================

-- Add new style 'chip' based on Mantine Chip component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'chip',
    
    get_style_group_id('mantine'),
    'Mantine Chip component for selectable tags',
    0
);

-- Add Chip-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_chip_variant', get_field_type_id('select'), 0, '{"searchable": false, "clearable": false, "options":[
{"value":"filled","text":"Filled"},
{"value":"outline","text":"Outline"},
{"value":"light","text":"Light"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('mantine_chip_variant'), 'filled', 'Sets the variant of the chip. For more information check https://mantine.dev/core/chip', 0, 0, 'Variant');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('mantine_size'), 'sm', 'Sets the size of the chip. For more information check https://mantine.dev/core/chip', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the chip. For more information check https://mantine.dev/core/chip', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('mantine_color'), 'blue', 'Sets the color of the chip. For more information check https://mantine.dev/core/chip', 0, 0, 'Color');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'chip_checked', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('chip_checked'), '0', 'If `checked` prop is set, chip will be in checked state. For more information check https://mantine.dev/core/chip', 0, 0, 'Checked');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('disabled'), '0', 'If `disabled` prop is set Chip will be disabled. For more information check https://mantine.dev/core/chip', 0, 0, 'Disabled');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Chip will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/chip', 0, 1, 'Use Mantine Style');

-- Add Chip-specific icon field
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('mantine_left_icon'), NULL, 'Sets the icon for the chip. For more information check https://mantine.dev/core/chip', 0, 0, 'Icon');

-- Add Chip-specific icon size field
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('mantine_icon_size'), '16', 'Sets the size of the chip icon in pixels. Choose from preset sizes or enter a custom value (e.g., 12, 14, 16, 18, 20, 24, 32). For more information check https://mantine.dev/core/chip', 0, 0, 'Icon Size');

-- Form configuration fields for chip (similar to checkbox)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('name'), NULL, 'Field name for form submission. Either a custom value or falls back to section-${style.id}', 0, 0, 'Field Name');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('value'), NULL, 'Default value for the chip field', 0, 0, 'Value');

-- Add chip form value configuration fields (similar to switch)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'chip_on_value', get_field_type_id('text'), 0, '{"placeholder": "1"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('chip_on_value'), '1', 'Value to submit when chip is checked/selected. For more information check https://mantine.dev/core/chip', 0, 0, 'On Value');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'chip_off_value', get_field_type_id('text'), 0, '{"placeholder": "0"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('chip_off_value'), '0', 'Value to submit when chip is unchecked/unselected. For more information check https://mantine.dev/core/chip', 0, 0, 'Off Value');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('is_required'), '0', 'Makes the chip field required for form submission', 0, 0, 'Required');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('label'), NULL, 'If this field is set, a this text will be rendered inside the chip.', 0, 0, 'Label');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('tooltip'), NULL, 'Optional tooltip text that will be displayed when hovering over the chip. Leave empty to disable tooltip.', 0, 0, 'Tooltip');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('chip'), get_field_id('mantine_tooltip_position'), 'top', 'Sets the position where the tooltip will appear relative to the chip.', 0, 0, 'Tooltip Position');

-- ===========================================
-- COLOR INPUT COMPONENT
-- ===========================================

-- Add new style 'color-input' based on Mantine color-input component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'color-input',
    
    get_style_group_id('mantine'),
    'Mantine color-input component for color selection',
    0
);

-- Add new style 'image' based on Mantine Image component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'image',
    
    get_style_group_id('mantine'),
    'Mantine Image component for displaying images with various fit options and fallback support',
    0
);

-- Create image-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_image_fit', get_field_type_id('select'), 0, '{"searchable": false, "clearable": false, "options":[
{"value":"contain","text":"Contain (fit entire image)"},
{"value":"cover","text":"Cover (fill container)"},
{"value":"fill","text":"Fill (stretch to fill)"},
{"value":"none","text":"None (original size)"},
{"value":"scale-down","text":"Scale Down (smaller of none/contain)"}
]}');

-- Link fields to image style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('image'), get_field_id('mantine_image_fit'), 'contain', 'Sets how the image should fit within its container. For more information check https://mantine.dev/core/image', 0, 0, 'Object Fit'),
(get_style_id('image'), get_field_id('mantine_width'), NULL, 'Sets the width of the image. Either a custom value or falls back to section-${style.id}', 0, 0, 'Width'),
(get_style_id('image'), get_field_id('mantine_height'), NULL, 'Sets the height of the image. Either a custom value or falls back to section-${style.id}', 0, 0, 'Height'),
(get_style_id('image'), get_field_id('mantine_radius'), '0', 'Sets the border radius of the image. For more information check https://mantine.dev/core/image', 0, 0, 'Radius'),
(get_style_id('image'), get_field_id('use_mantine_style'), '1', 'If enabled, uses Mantine Image component with advanced features. If disabled, falls back to standard HTML img element that can be styled with CSS.', 0, 0, 'Use Mantine Style');

-- Add unified color format field (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_color_format', get_field_type_id('segment'), 0, '{"options":[
{"value":"hex","text":"Hex"},
{"value":"rgba","text":"RGBA"},
{"value":"hsla","text":"HSLA"}
]}');

-- Add unified size field (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_size', get_field_type_id('select'), 0, '{"searchable": false, "clearable": false, "options":[
{"value":"xs","text":"Extra Small"},
{"value":"sm","text":"Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value":"xl","text":"Extra Large"}
]}');

-- Unified radius field already defined at the top of the file

-- Add unified icon fields (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_left_icon', get_field_type_id('select-icon'), 0, null);
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_right_icon', get_field_type_id('select-icon'), 0, null);

-- Add unified orientation field (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_orientation', get_field_type_id('segment'), 0, '{"options":[
{"value":"horizontal","text":"Horizontal"},
{"value":"vertical","text":"Vertical"}
]}');

-- Add unified color format field (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_color_format', get_field_type_id('segment'), 0, '{"options":[
{"value":"hex","text":"Hex"},
{"value":"rgba","text":"RGBA"},
{"value":"hsla","text":"HSLA"}
]}');

-- Add unified numeric fields (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_numeric_min', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"0","text":"0"},
{"value":"1","text":"1"},
{"value":"10","text":"10"},
{"value":"100","text":"100"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_numeric_max', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"10","text":"10"},
{"value":"100","text":"100"},
{"value":"1000","text":"1000"},
{"value":"10000","text":"10000"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_numeric_step', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"0.1","text":"0.1"},
{"value":"0.5","text":"0.5"},
{"value":"1","text":"1"},
{"value":"5","text":"5"},
{"value":"10","text":"10"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-input'), get_field_id('mantine_color_format'), 'hex', 'Sets the format of the color input. For more information check https://mantine.dev/core/color-input', 0, 0, 'Format');
-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-input'), get_field_id('mantine_size'), 'sm', 'Sets the size of the color input. For more information check https://mantine.dev/core/color-input', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-input'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the color input. For more information check https://mantine.dev/core/color-input', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-input'), get_field_id('placeholder'), 'Pick a color', 'Sets the placeholder text for the color input. For more information check https://mantine.dev/core/color-input', 0, 0, 'Placeholder');

-- Update placeholder field to be translatable
UPDATE `fields` SET `display` = 1 WHERE `name` = 'placeholder';

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-input'), get_field_id('disabled'), '0', 'If `disabled` prop is set color-input will be disabled. For more information check https://mantine.dev/core/color-input', 0, 0, 'Disabled');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-input'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set color-input will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/color-input', 0, 1, 'Use Mantine Style');

-- Form integration fields for color-input (similar to color-picker)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-input'), get_field_id('name'), '', 'Field name for form submission', 0, 0, 'Name');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-input'), get_field_id('value'), '', 'Default color value for the color input. Supports hex, rgba, or hsl formats', 0, 0, 'Value');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-input'), get_field_id('is_required'), '0', 'If set, the color selection becomes required for form submission', 0, 0, 'Required');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-input'), get_field_id('label'), '', 'Sets the label of the input field. For more information check https://mantine.dev/core/color-input', 0, 0, 'Label');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-input'), get_field_id('description'), '', 'Description text displayed below the input field', 0, 0, 'Description');

-- ===========================================
-- COLOR PICKER COMPONENT
-- ===========================================

-- Add new style 'color-picker' based on Mantine color-picker component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'color-picker',
    
    get_style_group_id('mantine'),
    'Mantine color-picker component for color selection',
    0
);

-- Use unified color format field for color-picker
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-picker'), get_field_id('mantine_color_format'), 'hex', 'Sets the format of the color picker. For more information check https://mantine.dev/core/color-picker', 0, 0, 'Format');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_color_picker_swatches_per_row', get_field_type_id('slider'), 0, '{
	"options": [
		{"value": "3", "text": "3"},
		{"value": "4", "text": "4"},
		{"value": "5", "text": "5"},
		{"value": "6", "text": "6"},
		{"value": "7", "text": "7"},
		{"value": "8", "text": "8"}
	]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-picker'), get_field_id('mantine_color_picker_swatches_per_row'), '7', 'Sets the number of swatches per row. For more information check https://mantine.dev/core/color-picker', 0, 0, 'Swatches Per Row');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-picker'), get_field_id('label'), '', 'Sets the label of the input field. For more information check https://mantine.dev/core/color-picker', 0, 0, 'Label');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-picker'), get_field_id('description'), '', 'Description text displayed below the input field', 0, 0, 'Description');

-- Add color-picker-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_color_picker_swatches', get_field_type_id('textarea'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-picker'), get_field_id('mantine_color_picker_swatches'), '["#2e2e2e", "#868e96", "#fa5252", "#e64980", "#be4bdb", "#7950f2", "#4c6ef5", "#228be6"]', 'Array of predefined color swatches. Enter as JSON array of hex color strings. For more information check https://mantine.dev/core/color-picker', 0, 0, 'Color Swatches');

-- Removed mantine_color_picker_value and mantine_color_picker_default_value - using global value field instead

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_color_picker_saturation_label', get_field_type_id('text'), 1, '{"placeholder": "Saturation"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-picker'), get_field_id('mantine_color_picker_saturation_label'), 'Saturation', 'Accessibility label for the saturation slider. For more information check https://mantine.dev/core/color-picker', 0, 0, 'Saturation Label');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_color_picker_hue_label', get_field_type_id('text'), 1, '{"placeholder": "Hue"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-picker'), get_field_id('mantine_color_picker_hue_label'), 'Hue', 'Accessibility label for the hue slider. For more information check https://mantine.dev/core/color-picker', 0, 0, 'Hue Label');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_color_picker_alpha_label', get_field_type_id('text'), 1, '{"placeholder": "Alpha"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-picker'), get_field_id('mantine_color_picker_alpha_label'), 'Alpha', 'Accessibility label for the alpha slider. For more information check https://mantine.dev/core/color-picker', 0, 0, 'Alpha Label');

-- Form integration fields (similar to ChipStyle)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-picker'), get_field_id('name'), '', 'Field name for form submission', 0, 0, 'Name');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-picker'), get_field_id('value'), '', 'Default color value for the color picker. Supports hex, rgba, or hsl formats', 0, 0, 'Value');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-picker'), get_field_id('is_required'), '0', 'If set, the color selection becomes required for form submission', 0, 0, 'Required');
-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-picker'), get_field_id('mantine_size'), 'sm', 'Sets the size of the color picker. For more information check https://mantine.dev/core/color-picker', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-picker'), get_field_id('mantine_fullwidth'), '0', 'If set, the color picker will take the full width of its container. For more information check https://mantine.dev/core/color-picker', 0, 0, 'Full Width');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('color-picker'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set color-picker will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/color-picker', 0, 1, 'Use Mantine Style');

-- ===========================================
-- FIELDSET COMPONENT
-- ===========================================

-- Add new style 'fieldset' based on Mantine Fieldset component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'fieldset',
    
    get_style_group_id('mantine'),
    'Mantine Fieldset component for grouping form elements',
    1
);

-- Add Fieldset-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('fieldset'), get_field_id('label'), NULL, 'Sets the legend/title of the fieldset. For more information check https://mantine.dev/core/fieldset', 0, 0, 'Legend');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_fieldset_variant', get_field_type_id('select'), 0, '{"searchable": false, "clearable": false, "options":[
{"value":"default","text":"Default"},
{"value":"filled","text":"Filled"},
{"value":"unstyled","text":"Unstyled"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('fieldset'), get_field_id('mantine_fieldset_variant'), 'default', 'Sets the variant of the fieldset. For more information check https://mantine.dev/core/fieldset', 0, 0, 'Variant');

-- Add disabled field for fieldset
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('fieldset'), get_field_id('disabled'), '0', 'If set, disables all inputs and buttons inside the fieldset. For more information check https://mantine.dev/core/fieldset', 0, 0, 'Disabled');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('fieldset'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the fieldset. For more information check https://mantine.dev/core/fieldset', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('fieldset'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Fieldset will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/fieldset', 0, 1, 'Use Mantine Style');

-- ===========================================
-- FILE INPUT COMPONENT
-- ===========================================

-- Add new style 'file-input' based on Mantine FileInput component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'file-input',
    
    get_style_group_id('mantine'),
    'Mantine FileInput component for file uploads',
    0
);

-- Add FileInput-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_file_input_multiple', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('mantine_file_input_multiple'), '0', 'If `multiple` prop is set, multiple files can be selected. For more information check https://mantine.dev/core/file-input', 0, 0, 'Multiple');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_file_input_accept', get_field_type_id('select'), 0, '{"creatable": true, "searchable": true, "clearable": true, "placeholder": "image/*", "options":[
{"value":"image/*","text":"All Images"},
{"value":"image/png,image/jpeg,image/gif","text":"Common Images (PNG, JPG, GIF)"},
{"value":"image/png","text":"PNG Images"},
{"value":"image/jpeg","text":"JPEG Images"},
{"value":"image/webp","text":"WebP Images"},
{"value":"audio/*","text":"All Audio Files"},
{"value":"video/*","text":"All Video Files"},
{"value":".pdf","text":"PDF Documents"},
{"value":".doc,.docx","text":"Word Documents"},
{"value":".xls,.xlsx","text":"Excel Files"},
{"value":".ppt,.pptx","text":"PowerPoint Files"},
{"value":".txt","text":"Text Files"},
{"value":".zip,.rar","text":"Archive Files"},
{"value":"application/json","text":"JSON Files"},
{"value":"text/csv","text":"CSV Files"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('mantine_file_input_accept'), NULL, 'Sets the accepted file types for the file input. Choose from presets or enter custom MIME types separated by commas. For more information check https://mantine.dev/core/file-input', 0, 0, 'Accept');

-- Add new FileInput-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_file_input_clearable', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('mantine_file_input_clearable'), '1', 'If set, displays a clear button when files are selected. For more information check https://mantine.dev/core/file-input', 0, 0, 'Clearable');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_file_input_max_size', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "placeholder": "5242880", "options":[
{"value":"1024","text":"1 KB"},
{"value":"10240","text":"10 KB"},
{"value":"102400","text":"100 KB"},
{"value":"524288","text":"512 KB"},
{"value":"1048576","text":"1 MB"},
{"value":"2097152","text":"2 MB"},
{"value":"5242880","text":"5 MB"},
{"value":"10485760","text":"10 MB"},
{"value":"20971520","text":"20 MB"},
{"value":"52428800","text":"50 MB"},
{"value":"104857600","text":"100 MB"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('mantine_file_input_max_size'), NULL, 'Sets the maximum file size in bytes. Choose from presets or enter a custom value. For more information check https://mantine.dev/core/file-input', 0, 0, 'Max File Size');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_file_input_max_files', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "placeholder": "5", "options":[
{"value":"1","text":"1 file"},
{"value":"3","text":"3 files"},
{"value":"5","text":"5 files"},
{"value":"10","text":"10 files"},
{"value":"20","text":"20 files"},
{"value":"50","text":"50 files"},
{"value":"100","text":"100 files"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('mantine_file_input_max_files'), NULL, 'Sets the maximum number of files that can be selected when multiple is enabled. Choose from presets or enter a custom value. For more information check https://mantine.dev/core/file-input', 0, 0, 'Max Files');

-- Add icon fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('mantine_left_icon'), NULL, 'Sets the icon displayed in the left section of the file input. For more information check https://mantine.dev/core/file-input', 0, 0, 'Left Icon');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('mantine_right_icon'), NULL, 'Sets the icon displayed in the right section of the file input. For more information check https://mantine.dev/core/file-input', 0, 0, 'Right Icon');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('mantine_size'), 'sm', 'Sets the size of the file input. For more information check https://mantine.dev/core/file-input', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the file input. For more information check https://mantine.dev/core/file-input', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('placeholder'), 'Select files', 'Sets the placeholder text for the file input. For more information check https://mantine.dev/core/file-input', 0, 0, 'Placeholder');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('disabled'), '0', 'If `disabled` prop is set FileInput will be disabled. For more information check https://mantine.dev/core/file-input', 0, 0, 'Disabled');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('is_required'), '0', 'If `is_required` prop is set FileInput will be required. For more information check https://mantine.dev/core/file-input', 0, 0, 'Required');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('name'), NULL, 'Sets the name attribute for the file input, used for form submission. If not set, falls back to section-${style.id}. For more information check https://mantine.dev/core/file-input', 0, 0, 'Name');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('label'), NULL, 'Sets the label for the file input. For more information check https://mantine.dev/core/file-input', 0, 0, 'Label');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('description'), NULL, 'Sets the description for the file input. For more information check https://mantine.dev/core/file-input', 0, 0, 'Description');

-- Add drag and drop field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_file_input_drag_drop', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('mantine_file_input_drag_drop'), '0', 'If set, enables drag and drop functionality for file uploads. For more information check https://mantine.dev/core/file-input', 0, 0, 'Enable Drag & Drop');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('file-input'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set FileInput will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/file-input', 0, 1, 'Use Mantine Style');

-- ===========================================
-- NUMBER INPUT COMPONENT
-- ===========================================

-- Add new style 'number-input' based on Mantine NumberInput component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'number-input',
    
    get_style_group_id('mantine'),
    'Mantine NumberInput component for numeric input',
    0
);

-- Add unified numeric fields (reusable across components)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_numeric_min', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"0","text":"0"},
{"value":"1","text":"1"},
{"value":"10","text":"10"},
{"value":"100","text":"100"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('mantine_numeric_min'), NULL, 'Sets the minimum value for the number input. For more information check https://mantine.dev/core/number-input', 0, 0, 'Min');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_numeric_max', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"10","text":"10"},
{"value":"100","text":"100"},
{"value":"1000","text":"1000"},
{"value":"10000","text":"10000"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('mantine_numeric_max'), NULL, 'Sets the maximum value for the number input. For more information check https://mantine.dev/core/number-input', 0, 0, 'Max');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_numeric_step', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"0.1","text":"0.1"},
{"value":"0.5","text":"0.5"},
{"value":"1","text":"1"},
{"value":"5","text":"5"},
{"value":"10","text":"10"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('mantine_numeric_step'), '1', 'Sets the step value for the number input. For more information check https://mantine.dev/core/number-input', 0, 0, 'Step');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_number_input_decimal_scale', get_field_type_id('slider'), 0, '{
	"options": [
		{"value": "0", "text": "0"},
		{"value": "1", "text": "1"},
		{"value": "2", "text": "2"},
		{"value": "3", "text": "3"},
		{"value": "4", "text": "4"},
		{"value": "5", "text": "5"}
	]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('mantine_number_input_decimal_scale'), '2', 'Sets the number of decimal places for the number input. For more information check https://mantine.dev/core/number-input', 0, 0, 'Decimal Scale');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_number_input_clamp_behavior', get_field_type_id('segment'), 0, '{"options":[
{"value":"strict","text":"Strict"},
{"value":"blur","text":"Blur"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('mantine_number_input_clamp_behavior'), 'strict', 'Sets the clamp behavior for the number input. For more information check https://mantine.dev/core/number-input', 0, 0, 'Clamp Behavior');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('mantine_size'), 'sm', 'Sets the size of the number input. For more information check https://mantine.dev/core/number-input', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the number input. For more information check https://mantine.dev/core/number-input', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('placeholder'), 'Enter number', 'Sets the placeholder text for the number input. For more information check https://mantine.dev/core/number-input', 0, 0, 'Placeholder');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('label'), '', 'Sets the label of the input field. For more information check https://mantine.dev/core/number-input', 0, 0, 'Label');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('description'), '', 'Description text displayed below the input field', 0, 0, 'Description');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('name'), '', 'Field name for form submission', 0, 0, 'Name');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('value'), '', 'Default numeric value for the number input', 0, 0, 'Value');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('is_required'), '0', 'If set, the number input becomes required for form submission', 0, 0, 'Required');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('disabled'), '0', 'If `disabled` prop is set NumberInput will be disabled. For more information check https://mantine.dev/core/number-input', 0, 0, 'Disabled');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('number-input'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set NumberInput will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/number-input', 0, 1, 'Use Mantine Style');


-- ===========================================
-- RADIO COMPONENT
-- ===========================================

-- Add new style 'radio' based on Mantine Radio component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'radio',
    
    get_style_group_id('mantine'),
    'Unified Radio component that can render as single radio or radio group based on options',
    1
);

-- Add Radio-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('radio'), get_field_id('label'), NULL, 'Sets the label for the radio button or radio group. For more information check https://mantine.dev/core/radio', 0, 0, 'Label');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('radio'), get_field_id('description'), NULL, 'Sets the description for the radio button. For more information check https://mantine.dev/core/radio', 0, 0, 'Description');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES (get_style_id('radio'), get_field_id('name'), NULL, 'Sets the form field name for the radio button or radio group. For more information check https://mantine.dev/core/radio', 0, 0, 'Field Name');
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES (get_style_id('radio'), get_field_id('value'), NULL, 'Sets the initial selected value for the radio button or radio group. For more information check https://mantine.dev/core/radio', 0, 0, 'Value');
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES (get_style_id('radio'), get_field_id('is_required'), '0', 'Makes the radio button or radio group required for form submission. For more information check https://mantine.dev/core/radio', 0, 0, 'Required');

-- Add Radio options field (for group functionality)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_radio_options', get_field_type_id('json'), 1, '{"rows": 5, "placeholder": "Enter JSON array of radio options"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('radio'), get_field_id('mantine_radio_options'), '[{"value":"option1","text":"Option 1","description":"First choice description"},{"value":"option2","text":"Option 2","description":"Second choice description"},{"value":"option3","text":"Option 3","description":"Third choice description"}]', 'Sets the options for the radio group as JSON array. If provided, renders as Radio.Group. Format: [{"value":"1","text":"Item1","description":"Optional description"}]. For more information check https://mantine.dev/core/radio', 0, 0, 'Options');

-- Use unified orientation field for radio group
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('radio'), get_field_id('mantine_orientation'), 'vertical', 'Sets the orientation of the radio group (when options are provided). For more information check https://mantine.dev/core/radio', 0, 0, 'Orientation');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('radio'), get_field_id('mantine_size'), 'sm', 'Sets the size of the radio button or radio group. For more information check https://mantine.dev/core/radio', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('radio'), get_field_id('mantine_color'), 'blue', 'Sets the color of the radio button or radio group. For more information check https://mantine.dev/core/radio', 0, 0, 'Color');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('radio'), get_field_id('disabled'), '0', 'If `disabled` prop is set Radio will be disabled. For more information check https://mantine.dev/core/radio', 0, 0, 'Disabled');

-- Add label position field for radio components
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_radio_label_position', get_field_type_id('select'), 0, '{"searchable": false, "clearable": false, "options":[{"value": "right", "text": "Right (default)"}, {"value": "left", "text": "Left"}]}');

-- Add radio card option field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_radio_card', get_field_type_id('checkbox'), 0, null);

-- Add radio variant field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_radio_variant', get_field_type_id('select'), 0, '{"searchable": false, "clearable": false, "options":[{"value": "default", "text": "Default"}, {"value": "outline", "text": "Outline"}]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('radio'), get_field_id('mantine_radio_label_position'), 'right', 'Sets the position of the label relative to the radio button. For more information check https://mantine.dev/core/radio', 0, 0, 'Label Position');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('radio'), get_field_id('mantine_radio_card'), '0', 'If set, renders radio options as card components instead of standard radio buttons. For more information check https://mantine.dev/core/radio', 0, 0, 'Use Radio Card');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('radio'), get_field_id('mantine_radio_variant'), 'default', 'Sets the visual variant of the radio component. For more information check https://mantine.dev/core/radio', 0, 0, 'Variant');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('radio'), get_field_id('mantine_tooltip_label'), NULL, 'Sets the tooltip text for the radio component. Leave empty to disable tooltip. For more information check https://mantine.dev/core/tooltip', 0, 0, 'Tooltip Label');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('radio'), get_field_id('mantine_tooltip_position'), 'top', 'Sets the position of the tooltip. For more information check https://mantine.dev/core/tooltip', 0, 0, 'Tooltip Position');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('radio'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Radio will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/radio', 0, 1, 'Use Mantine Style'),
(get_style_id('radio'), get_field_id('mantine_use_input_wrapper'), 1, 'When enabled, uses Input.Wrapper for proper label and description handling. When disabled, renders label and description inline next to the radio buttons.', 0, 0, 'Use Input Wrapper');

-- ===========================================
-- RANGE SLIDER COMPONENT
-- ===========================================

-- Add new style 'range-slider' based on Mantine range-slider component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'range-slider',
    
    get_style_group_id('mantine'),
    'Mantine range-slider component for range selection',
    0
);

-- Use unified numeric fields for range-slider
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('mantine_numeric_min'), '0', 'Sets the minimum value for the range slider. For more information check https://mantine.dev/core/range-slider', 0, 0, 'Min');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('mantine_numeric_max'), '100', 'Sets the maximum value for the range slider. For more information check https://mantine.dev/core/range-slider', 0, 0, 'Max');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('mantine_numeric_step'), '1', 'Sets the step value for the range slider. For more information check https://mantine.dev/core/range-slider', 0, 0, 'Step');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('mantine_size'), 'sm', 'Sets the size of the range slider. For more information check https://mantine.dev/core/range-slider', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('mantine_color'), 'blue', 'Sets the color of the range slider. For more information check https://mantine.dev/core/range-slider', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('disabled'), '0', 'If `disabled` prop is set range-slider will be disabled. For more information check https://mantine.dev/core/range-slider', 0, 0, 'Disabled');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set range-slider will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/range-slider', 0, 1, 'Use Mantine Style');

-- Add mantine_radius field (reuse existing)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the range slider. For more information check https://mantine.dev/core/range-slider', 0, 0, 'Radius');

-- Create new field for translatable marks values (display = 1)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_range_slider_marks_values', get_field_type_id('textarea'), 1, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('mantine_range_slider_marks_values'), '', 'Translatable values for range slider marks in JSON format. Example: [{"value":25,"label":"Low"},{"value":50,"label":"Medium"},{"value":75,"label":"High"}]. For more information check https://mantine.dev/core/range-slider', 0, 0, 'Marks Values');

-- Create field for show label on hover
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_range_slider_show_label', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('mantine_range_slider_show_label'), '1', 'If enabled, shows label on hover for range slider. For more information check https://mantine.dev/core/range-slider', 0, 0, 'Show Label on Hover');

-- Create field for labels always on
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_range_slider_labels_always_on', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('mantine_range_slider_labels_always_on'), '0', 'If enabled, labels are always visible on the range slider. For more information check https://mantine.dev/core/range-slider', 0, 0, 'Labels Always On');

-- Create field for inverted slider
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_range_slider_inverted', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('mantine_range_slider_inverted'), '0', 'If enabled, inverts the range slider track and thumb colors. For more information check https://mantine.dev/core/range-slider', 0, 0, 'Inverted');

-- Add standard input fields for range-slider
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('label'), '', 'Sets the label text for the range slider input field', 0, 0, 'Label');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('description'), '', 'Sets the description text displayed below the range slider input field', 0, 0, 'Description');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('name'), '', 'Sets the name attribute for the range slider input field, used for form integration', 0, 0, 'Name');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('range-slider'), get_field_id('value'), '', 'Sets the value attribute for the range slider input field, used for form integration. Example: [20, 40]', 0, 0, 'Value');


-- ===========================================
-- SLIDER COMPONENT
-- ===========================================

-- Add new style 'slider' based on Mantine slider component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'slider',
    
    get_style_group_id('mantine'),
    'Mantine slider component for single value selection',
    0
);

-- Use unified numeric fields for slider
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('mantine_numeric_min'), '0', 'Sets the minimum value for the slider. For more information check https://mantine.dev/core/slider', 0, 0, 'Min');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('mantine_numeric_max'), '100', 'Sets the maximum value for the slider. For more information check https://mantine.dev/core/slider', 0, 0, 'Max');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('mantine_numeric_step'), '1', 'Sets the step value for the slider. For more information check https://mantine.dev/core/slider', 0, 0, 'Step');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('mantine_size'), 'sm', 'Sets the size of the slider. For more information check https://mantine.dev/core/slider', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('mantine_color'), 'blue', 'Sets the color of the slider. For more information check https://mantine.dev/core/slider', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('disabled'), '0', 'If set, the slider will be disabled. For more information check https://mantine.dev/core/slider', 0, 0, 'Disabled');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the slider component', 0, 1, 'Use Mantine Style');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the slider. For more information check https://mantine.dev/core/slider', 0, 0, 'Radius');

-- Add slider-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_slider_marks_values', get_field_type_id('textarea'), 1, null);
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('mantine_slider_marks_values'), '', 'Translatable values for slider marks in JSON format. Example: [{"value":25,"label":"Low"},{"value":50,"label":"Medium"},{"value":75,"label":"High"}]. For more information check https://mantine.dev/core/slider', 0, 0, 'Marks Values');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_slider_show_label', get_field_type_id('checkbox'), 0, null);
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('mantine_slider_show_label'), '1', 'If enabled, shows label on hover for slider. For more information check https://mantine.dev/core/slider', 0, 0, 'Show Label on Hover');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_slider_labels_always_on', get_field_type_id('checkbox'), 0, null);
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('mantine_slider_labels_always_on'), '0', 'If enabled, labels are always visible on the slider. For more information check https://mantine.dev/core/slider', 0, 0, 'Labels Always On');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_slider_inverted', get_field_type_id('checkbox'), 0, null);
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('mantine_slider_inverted'), '0', 'If enabled, inverts the slider track and thumb colors. For more information check https://mantine.dev/core/slider', 0, 0, 'Inverted');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_slider_thumb_size', get_field_type_id('creatable_select'), 0, '{
"creatable": true,
"searchable": false,
"clearable": false,
"placeholder": "16",
"options": [
{"value": "12", "text": "Extra Small (12px)"},
{"value": "14", "text": "Small (14px)"},
{"value": "16", "text": "Medium (16px)"},
{"value": "18", "text": "Large (18px)"},
{"value": "20", "text": "Extra Large (20px)"},
{"value": "24", "text": "XL (24px)"},
{"value": "32", "text": "XXL (32px)"}
]
}');
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('mantine_slider_thumb_size'), '16', 'Sets the thumb size in pixels. Choose from preset sizes or enter a custom value (e.g., 12, 14, 16, 18, 20, 24, 32). For more information check https://mantine.dev/core/slider', 0, 0, 'Thumb Size');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_slider_required', get_field_type_id('checkbox'), 0, null);
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('mantine_slider_required'), '0', 'If enabled, the slider will be required for form submission. For more information check https://mantine.dev/core/slider', 0, 0, 'Required');

-- Add standard input fields for slider
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('label'), '', 'Sets the label text for the slider input field', 0, 0, 'Label');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('description'), '', 'Sets the description text displayed below the slider input field', 0, 0, 'Description');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('name'), '', 'Sets the name attribute for the slider input field, used for form integration', 0, 0, 'Name');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('slider'), get_field_id('value'), '', 'Sets the value attribute for the slider input field, used for form integration. Example: 50', 0, 0, 'Value');

-- ===========================================
-- RATING COMPONENT
-- ===========================================

-- Add new style 'rating' based on Mantine Rating component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'rating',
    
    get_style_group_id('mantine'),
    'Mantine Rating component for star ratings',
    0
);

-- Add Rating-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_rating_count', get_field_type_id('slider'), 0, '{
	"options": [
		{"value": "3", "text": "3"},
		{"value": "4", "text": "4"},
		{"value": "5", "text": "5"},
		{"value": "6", "text": "6"},
		{"value": "7", "text": "7"},
		{"value": "8", "text": "8"},
		{"value": "9", "text": "9"},
		{"value": "10", "text": "10"}
	]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('mantine_rating_count'), '5', 'Sets the number of stars in the rating. For more information check https://mantine.dev/core/rating', 0, 0, 'Count');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_rating_fractions', get_field_type_id('slider'), 0, '{
	"options": [
		{"value": "1", "text": "Whole"},
		{"value": "2", "text": "Halves"},
		{"value": "3", "text": "Thirds"},
		{"value": "4", "text": "Quarters"},
		{"value": "5", "text": "Fifths"}
	]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('mantine_rating_fractions'), '1', 'Sets the fraction precision for the rating. For more information check https://mantine.dev/core/rating', 0, 0, 'Fractions');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_rating_use_smiles', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('mantine_rating_use_smiles'), '0', 'If enabled, uses smiley face icons (sad to happy) instead of stars. When enabled, the count is automatically fixed to 5. For more information check https://mantine.dev/core/rating', 0, 0, 'Use Smiles');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_rating_empty_icon', get_field_type_id('select-icon'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('mantine_rating_empty_icon'), NULL, 'Sets the icon for unselected rating items. For more information check https://mantine.dev/core/rating', 0, 0, 'Empty Icon');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_rating_full_icon', get_field_type_id('select-icon'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('mantine_rating_full_icon'), NULL, 'Sets the icon for selected rating items. For more information check https://mantine.dev/core/rating', 0, 0, 'Full Icon');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_rating_highlight_selected_only', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('mantine_rating_highlight_selected_only'), '0', 'If enabled, only selected items will be highlighted, unselected items will be dimmed. For more information check https://mantine.dev/core/rating', 0, 0, 'Highlight Selected Only');

-- Standard input fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('label'), NULL, 'Label text for the rating input field', 0, 0, 'Label');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('description'), NULL, 'Description text for the rating input field', 0, 0, 'Description');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('name'), NULL, 'Name attribute for the rating input field (required for form submission)', 0, 0, 'Name');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('disabled'), '0', 'If set, the rating will be disabled and cannot be interacted with', 0, 0, 'Disabled');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('value'), NULL, 'Initial value for the rating (number between 0 and count)', 0, 0, 'Value');

-- Global readonly field (moved from mantine-specific)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('readonly'), '0', 'If set, the rating will be read-only and cannot be changed', 0, 0, 'Read Only');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('mantine_size'), 'sm', 'Sets the size of the rating. For more information check https://mantine.dev/core/rating', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('mantine_color'), 'yellow', 'Sets the color of the rating. For more information check https://mantine.dev/core/rating', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('rating'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Rating will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/rating', 0, 1, 'Use Mantine Style');

-- ===========================================
-- SEGMENTED CONTROL COMPONENT
-- ===========================================

-- Add new style 'segmented-control' based on Mantine segmented-control component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'segmented-control',
    
    get_style_group_id('mantine'),
    'Mantine segmented-control component for segmented controls',
    0
);

-- Add segmented-control options field (text-based JSON)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_segmented_control_data', get_field_type_id('textarea'), 1, '{"rows": 3, "placeholder": "Enter JSON array of segmented control options"}');

-- Add item border field for segmented control
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_segmented_control_item_border', get_field_type_id('checkbox'), 0, NULL);

-- Add readonly field for segmented control
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'readonly', get_field_type_id('checkbox'), 0, NULL);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('mantine_segmented_control_data'), '[{"value":"option1","label":"Option 1"},{"value":"option2","label":"Option 2"},{"value":"option3","label":"Option 3"}]', 'Sets the data/options for the segmented control as JSON array. Format: [{"value":"option1","label":"Option 1"}]. For more information check https://mantine.dev/core/segmented-control', 0, 0, 'Data');

-- Use unified orientation field for segmented control
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('mantine_orientation'), 'horizontal', 'Sets the orientation of the segmented control. For more information check https://mantine.dev/core/segmented-control', 0, 0, 'Orientation');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('mantine_size'), 'sm', 'Sets the size of the segmented control. For more information check https://mantine.dev/core/segmented-control', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the segmented control. For more information check https://mantine.dev/core/segmented-control', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('mantine_color'), 'blue', 'Sets the color of the segmented control. For more information check https://mantine.dev/core/segmented-control', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('fullwidth'), '0', 'If `fullWidth` prop is set segmented-control will take 100% of parent width. For more information check https://mantine.dev/core/segmented-control', 0, 0, 'Full Width');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('disabled'), '0', 'If `disabled` prop is set segmented-control will be disabled. For more information check https://mantine.dev/core/segmented-control', 0, 0, 'Disabled');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set segmented-control will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/segmented-control', 0, 1, 'Use Mantine Style');

-- Add new fields for SegmentedControl
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('mantine_segmented_control_item_border'), '0', 'If set, adds border around each segmented control item. For more information check https://mantine.dev/core/segmented-control', 0, 0, 'Item Border');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('label'), NULL, 'Sets the label text for the segmented control input wrapper. For more information check https://mantine.dev/core/segmented-control', 0, 0, 'Label');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('description'), NULL, 'Sets the description text for the segmented control input wrapper. For more information check https://mantine.dev/core/segmented-control', 0, 0, 'Description');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('value'), NULL, 'Sets the default selected value for the segmented control. Either a custom value or falls back to section-${style.id}. For more information check https://mantine.dev/core/segmented-control', 0, 0, 'Value');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('readonly'), '0', 'If set, the segmented control will be readonly. For more information check https://mantine.dev/core/segmented-control', 0, 0, 'Read Only');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('is_required'), '0', 'If set, the segmented control will be required for form validation. For more information check https://mantine.dev/core/segmented-control', 0, 0, 'Required');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('segmented-control'), get_field_id('name'), NULL, 'Sets the name for the segmented control input wrapper. For more information check https://mantine.dev/core/segmented-control', 0, 0, 'Name');

-- ===========================================
-- SWITCH COMPONENT
-- ===========================================

-- Add new style 'switch' based on Mantine Switch component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'switch',
    
    get_style_group_id('mantine'),
    'Mantine Switch component for toggle switches',
    0
);

-- Add Switch-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('label'), NULL, 'Sets the label for the switch. For more information check https://mantine.dev/core/switch', 0, 0, 'Label');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('description'), NULL, 'Sets the description for the switch. For more information check https://mantine.dev/core/switch', 0, 0, 'Description');

-- Translatable text input fields (display = 1)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_switch_on_label', get_field_type_id('text'), 1, '{"placeholder": "Enter on label"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('mantine_switch_on_label'), 'On', 'Sets the label when switch is on. For more information check https://mantine.dev/core/switch', 0, 0, 'On Label');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_switch_off_label', get_field_type_id('text'), 1, '{"placeholder": "Enter off label"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('mantine_switch_off_label'), 'Off', 'Sets the label when switch is off. For more information check https://mantine.dev/core/switch', 0, 0, 'Off Label');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('mantine_size'), 'sm', 'Sets the size of the switch. For more information check https://mantine.dev/core/switch', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('mantine_color'), 'blue', 'Sets the color of the switch. For more information check https://mantine.dev/core/switch', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('disabled'), '0', 'If `disabled` prop is set Switch will be disabled. For more information check https://mantine.dev/core/switch', 0, 0, 'Disabled');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Switch will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/switch', 0, 1, 'Use Mantine Style');

-- Add controlled input fields for Switch
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('name'), NULL, 'Sets the name attribute for the switch input field, used for form integration. For more information check https://mantine.dev/core/switch', 0, 0, 'Name');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('is_required'), '0', 'If set, the switch will be marked as required for form validation. For more information check https://mantine.dev/core/switch', 0, 0, 'Required');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius for the switch. For more information check https://mantine.dev/core/switch', 0, 0, 'Radius');

-- Add label position field for Switch
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_label_position', get_field_type_id('segment'), 0, '{"options":[
{"value":"left","text":"Left"},   
{"value":"right","text":"Right"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('mantine_label_position'), 'left', 'Sets the position of the label relative to the switch. For more information check https://mantine.dev/core/switch', 0, 0, 'Label Position');

-- Add value field for Switch (the current value from form data)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('value'), NULL, 'Sets the current value of the switch for form integration. For more information check https://mantine.dev/core/switch', 0, 0, 'Current Value');

-- Add on value field for Switch (what value means the switch is on)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_switch_on_value', get_field_type_id('text'), 0, '{"placeholder": "Enter value for on state (e.g., 1, true, yes)"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('mantine_switch_on_value'), '1', 'Sets the value that represents the on/checked state of the switch. When the current value equals this value, the switch will be checked. For more information check https://mantine.dev/core/switch', 0, 0, 'On Value');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('switch'), get_field_id('mantine_use_input_wrapper'), 0,'When enabled, uses Input.Wrapper for proper label and description handling. When disabled, renders label and description inline next to the switch.', 0, 0, 'Use Input Wrapper');

-- ===========================================
-- COMBOBOX COMPONENT
-- ===========================================

-- Add new style 'combobox' based on Mantine Combobox component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'combobox',
    
    get_style_group_id('mantine'),
    'Mantine Combobox component for advanced select inputs',
    0
);

-- Add Combobox-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('combobox'), get_field_id('placeholder'), 'Select option', 'Sets the placeholder text for the combobox. For more information check https://mantine.dev/core/combobox', 0, 0, 'Placeholder');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_combobox_options', get_field_type_id('json'), 1, '{"rows": 3, "placeholder": "Enter JSON array of combobox options"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('combobox'), get_field_id('mantine_combobox_options'), '[{"value":"option1","text":"Option 1"},{"value":"option2","text":"Option 2"}]', 'Sets the data/options for the combobox as JSON array. Format: [{"value":"option1","text":"Option 1"}]. For more information check https://mantine.dev/core/combobox', 0, 0, 'Options');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('combobox'), get_field_id('disabled'), '0', 'If `disabled` prop is set Combobox will be disabled. For more information check https://mantine.dev/core/combobox', 0, 0, 'Disabled');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('combobox'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Combobox will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/combobox', 0, 1, 'Use Mantine Style');

-- Combobox configuration fields (similar to CreatableSelectField)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_combobox_multi_select', get_field_type_id('checkbox'), 0, null);
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_combobox_searchable', get_field_type_id('checkbox'), 0, null);
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_combobox_creatable', get_field_type_id('checkbox'), 0, null);
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_combobox_clearable', get_field_type_id('checkbox'), 0, null);
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_combobox_separator', get_field_type_id('text'), 0, '{"placeholder": " "}');

-- Form integration fields for combobox (similar to color-picker)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('combobox'), get_field_id('label'), '', 'Sets the label of the input field. For more information check https://mantine.dev/core/combobox', 0, 0, 'Label'),
(get_style_id('combobox'), get_field_id('description'), '', 'Description text displayed below the input field', 0, 0, 'Description'),
(get_style_id('combobox'), get_field_id('name'), '', 'Field name for form submission', 0, 0, 'Name'),
(get_style_id('combobox'), get_field_id('value'), '', 'Default value for the combobox', 0, 0, 'Value'),
(get_style_id('combobox'), get_field_id('is_required'), '0', 'If set, the selection becomes required for form submission', 0, 0, 'Required'),
-- Combobox configuration
(get_style_id('combobox'), get_field_id('mantine_combobox_multi_select'), '0', 'If set, allows selecting multiple options. Values will be joined with the separator.', 0, 0, 'Multi-Select'),
(get_style_id('combobox'), get_field_id('mantine_combobox_searchable'), '1', 'If set, enables search functionality in the dropdown.', 0, 0, 'Searchable'),
(get_style_id('combobox'), get_field_id('mantine_combobox_creatable'), '0', 'If set, allows users to create new options not in the predefined list.', 0, 0, 'Creatable'),
(get_style_id('combobox'), get_field_id('mantine_combobox_clearable'), '0', 'If set, allows clearing the selection in single-select mode.', 0, 0, 'Clearable'),
(get_style_id('combobox'), get_field_id('mantine_combobox_separator'), ' ', 'Separator used to join multiple selected values (only applies when multi-select is enabled).', 0, 0, 'Separator');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_multi_select_max_values', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"3","text":"3"},
{"value":"5","text":"5"},
{"value":"10","text":"10"},
{"value":"25","text":"25"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('combobox'), get_field_id('mantine_multi_select_max_values'), NULL, 'Sets the maximum number of values that can be selected. For more information check https://mantine.dev/core/multi-select', 0, 0, 'Max Values');

-- ===========================================
-- ACTION ICON COMPONENT
-- ===========================================

-- Add new style 'action-icon' based on Mantine ActionIcon component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'action-icon',
    
    get_style_group_id('mantine'),
    'Mantine ActionIcon component for interactive icons',
    0
);

-- Add ActionIcon-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('action-icon'), get_field_id('mantine_variant'), 'subtle', 'Sets the variant of the action icon. For more information check https://mantine.dev/core/action-icon', 0, 0, 'Variant');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_action_icon_loading', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('action-icon'), get_field_id('mantine_action_icon_loading'), '0', 'If `loading` prop is set, action icon will show loading state. For more information check https://mantine.dev/core/action-icon', 0, 0, 'Loading');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('action-icon'), get_field_id('mantine_size'), 'sm', 'Sets the size of the action icon. For more information check https://mantine.dev/core/action-icon', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('action-icon'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the action icon. For more information check https://mantine.dev/core/action-icon', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('action-icon'), get_field_id('mantine_color'), 'blue', 'Sets the color of the action icon. For more information check https://mantine.dev/core/action-icon', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('action-icon'), get_field_id('disabled'), '0', 'If `disabled` prop is set ActionIcon will be disabled. For more information check https://mantine.dev/core/action-icon', 0, 0, 'Disabled');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('action-icon'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set ActionIcon will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/action-icon', 0, 1, 'Use Mantine Style');

-- Add icon field for ActionIcon
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('action-icon'), get_field_id('mantine_left_icon'), NULL, 'Sets the icon for the action icon. For more information check https://mantine.dev/core/action-icon', 0, 0, 'Icon');

-- Add link-related fields for ActionIcon
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('action-icon'), get_field_id('is_link'), '0', 'If `isLink` prop is set ActionIcon will be a link. For more information check https://mantine.dev/core/action-icon', 0, 0, 'Is Link');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('action-icon'), get_field_id('page_keyword'), '#', 'Select a page keyword to link to. For more information check https://mantine.dev/core/action-icon', 0, 0, 'URL');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('action-icon'), get_field_id('open_in_new_tab'), '0', 'If `openInNewTab` prop is set ActionIcon will open in a new tab. For more information check https://mantine.dev/core/action-icon', 0, 0, 'Open in New Tab');

-- ===========================================
-- NOTIFICATION COMPONENT
-- ===========================================

-- Add new style 'notification' based on Mantine Notification component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'notification',
    
    get_style_group_id('mantine'),
    'Mantine Notification component for alerts and messages',
    0
);

-- Add Notification-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('notification'), get_field_id('title'), NULL, 'Sets the title for the notification. For more information check https://mantine.dev/core/notification', 0, 0, 'Title');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('notification'), get_field_id('mantine_color'), 'blue', 'Sets the color of the notification. For more information check https://mantine.dev/core/notification', 0, 0, 'Color');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_notification_loading', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('notification'), get_field_id('mantine_notification_loading'), '0', 'If `loading` prop is set, notification will show loading state. For more information check https://mantine.dev/core/notification', 0, 0, 'Loading');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_notification_with_close_button', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('notification'), get_field_id('mantine_notification_with_close_button'), '1', 'If `withCloseButton` prop is set, notification will have a close button. For more information check https://mantine.dev/core/notification', 0, 0, 'With Close Button');

-- Removed: mantine_notification_with_border (replaced with global mantine_border field)

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('notification'), get_field_id('mantine_border'), '0', 'If `withBorder` prop is set, notification will have a border. For more information check https://mantine.dev/core/notification', 0, 0, 'With Border');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('notification'), get_field_id('mantine_left_icon'), NULL, 'Sets the icon for the notification. If no icon is selected, a default icon matching the color will be used. For more information check https://mantine.dev/core/notification', 0, 0, 'Icon');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('notification'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the notification. For more information check https://mantine.dev/core/notification', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('notification'), get_field_id('content'), NULL, 'Sets the main content/message of the notification. For more information check https://mantine.dev/core/notification', 0, 0, 'Content');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('notification'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Notification will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/notification', 0, 1, 'Use Mantine Style');

-- ===========================================
-- ALERT COMPONENT
-- ===========================================

-- Add new style 'alert' based on Mantine Alert component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'alert',
    
    get_style_group_id('mantine'),
    'Mantine Alert component for displaying important messages and notifications',
    1
);

-- Add Alert-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_alert_title', get_field_type_id('text'), 1, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('alert'), get_field_id('mantine_alert_title'), NULL, 'Sets the title of the alert. For more information check https://mantine.dev/core/alert', 0, 0, 'Title');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_with_close_button', get_field_type_id('checkbox'), 0, null);

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('alert'), get_field_id('mantine_variant'), 'light', 'Sets the variant of the alert. For more information check https://mantine.dev/core/alert', 0, 0, 'Variant');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('alert'), get_field_id('mantine_color'), 'blue', 'Sets the color of the alert. For more information check https://mantine.dev/core/alert', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('alert'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the alert. For more information check https://mantine.dev/core/alert', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('alert'), get_field_id('mantine_left_icon'), NULL, 'Sets the icon for the alert. For more information check https://mantine.dev/core/alert', 0, 0, 'Icon');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('alert'), get_field_id('mantine_with_close_button'), '0', 'If set, the alert will have a close button. For more information check https://mantine.dev/core/alert', 0, 0, 'With Close Button');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('alert'), get_field_id('content'), NULL, 'Sets the main content/message of the alert. For more information check https://mantine.dev/core/alert', 0, 0, 'Content');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('alert'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the alert component', 0, 1, 'Use Mantine Style');

-- ===========================================
-- ACCORDION COMPONENT
-- ===========================================

-- Add new style 'accordion' based on Mantine Accordion component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'accordion',
    
    get_style_group_id('mantine'),
    'Mantine Accordion component for collapsible content',
    1
);

-- Add Accordion-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_accordion_variant', get_field_type_id('select'), 0, '{"searchable": false, "clearable": false, "options":[
{"value":"default","text":"Default"},
{"value":"contained","text":"Contained"},
{"value":"filled","text":"Filled"},
{"value":"separated","text":"Separated"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion'), get_field_id('mantine_accordion_variant'), 'default', 'Sets the variant of the accordion. For more information check https://mantine.dev/core/accordion', 0, 0, 'Variant');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_accordion_multiple', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion'), get_field_id('mantine_accordion_multiple'), '0', 'If `multiple` prop is set, multiple panels can be opened simultaneously. For more information check https://mantine.dev/core/accordion', 0, 0, 'Multiple');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_accordion_chevron_position', get_field_type_id('segment'), 0, '{"options":[
{"value":"left","text":"Left"},
{"value":"right","text":"Right"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion'), get_field_id('mantine_accordion_chevron_position'), 'right', 'Sets the position of the chevron icon. For more information check https://mantine.dev/core/accordion', 0, 0, 'Chevron Position');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_accordion_chevron_size', get_field_type_id('select'), 0, '{
"creatable": true,
"searchable": false,
"clearable": false,
"placeholder": "16",
"options": [
{"value": "14", "text": "Small (14px)"},
{"value": "16", "text": "Medium (16px)"},
{"value": "18", "text": "Large (18px)"},
{"value": "20", "text": "Extra Large (20px)"},
{"value": "24", "text": "XL (24px)"},
{"value": "32", "text": "XXL (32px)"}
]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion'), get_field_id('mantine_accordion_chevron_size'), '16', 'Sets the size of the chevron icon in pixels. Choose from preset sizes or enter a custom value (e.g., 12, 14, 16, 18, 20, 24, 32). For more information check https://mantine.dev/core/accordion', 0, 0, 'Chevron Size');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_accordion_disable_chevron_rotation', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion'), get_field_id('mantine_accordion_disable_chevron_rotation'), '0', 'If set, chevron icon will not rotate when item is opened. For more information check https://mantine.dev/core/accordion', 0, 0, 'Disable Chevron Rotation');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_accordion_loop', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion'), get_field_id('mantine_accordion_loop'), '1', 'If set, keyboard navigation will loop from last to first item and vice versa. For more information check https://mantine.dev/core/accordion', 0, 0, 'Loop Navigation');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_accordion_transition_duration', get_field_type_id('select'), 0, '{
"creatable": true,
"searchable": false,
"clearable": false,
"placeholder": "200",
"options": [
{"value": "0", "text": "Instant (0ms)"},
{"value": "150", "text": "Fast (150ms)"},
{"value": "200", "text": "Normal (200ms)"},
{"value": "300", "text": "Slow (300ms)"},
{"value": "400", "text": "Very Slow (400ms)"},
{"value": "500", "text": "Extra Slow (500ms)"}
]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion'), get_field_id('mantine_accordion_transition_duration'), '200', 'Sets the duration of expand/collapse transition in milliseconds. Choose from preset durations or enter a custom value (e.g., 100, 150, 200, 300, 400, 500). For more information check https://mantine.dev/core/accordion', 0, 0, 'Transition Duration');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_accordion_default_value', get_field_type_id('text'), 0, '{"placeholder": "item-1,item-2"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion'), get_field_id('mantine_accordion_default_value'), NULL, 'Sets the initially opened item(s). Use comma-separated values for multiple items (e.g., "item-1,item-2"). For more information check https://mantine.dev/core/accordion', 0, 0, 'Default Open Items');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the accordion. For more information check https://mantine.dev/core/accordion', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the accordion component', 0, 1, 'Use Mantine Style');

-- ===========================================
-- ACCORDION ITEM COMPONENT (child of accordion)
-- ===========================================

-- Add new style 'accordion-item' based on Mantine Accordion.Item component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'accordion-item',
    
    get_style_group_id('mantine'),
    'Mantine Accordion.Item component for individual accordion items (accepts all children, panels handled in frontend)',
    1
);

-- Add Accordion.Item-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_accordion_item_value', get_field_type_id('text'), 0, '{"placeholder": "unique-item-value"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion-item'), get_field_id('mantine_accordion_item_value'), NULL, 'Unique identifier for the accordion item. Either a custom value or falls back to section-${style.id}. This value is used to control which item is open. For more information check https://mantine.dev/core/accordion', 0, 0, 'Item Value');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion-item'), get_field_id('label'), NULL, 'Sets the label text displayed in the accordion control. For more information check https://mantine.dev/core/accordion', 0, 0, 'Label');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_accordion_item_icon', get_field_type_id('select-icon'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion-item'), get_field_id('mantine_accordion_item_icon'), NULL, 'Sets the icon displayed next to the label in the accordion control. For more information check https://mantine.dev/core/accordion', 0, 0, 'Icon');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion-item'), get_field_id('disabled'), '0', 'If set, the accordion item will be disabled and cannot be opened. For more information check https://mantine.dev/core/accordion', 0, 0, 'Disabled');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('accordion-item'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the accordion item component', 0, 1, 'Use Mantine Style');

-- Accordion Panel component removed - handled in frontend

-- ===========================================
-- AVATAR COMPONENT
-- ===========================================

-- Add new style 'avatar' based on Mantine Avatar component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'avatar',
    
    get_style_group_id('mantine'),
    'Mantine Avatar component for user profile images',
    0
);

-- Add Avatar-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('avatar'), get_field_id('img_src'), NULL, 'Sets the image source for the avatar. Has priority over icon and initials fields. Either img_src, icon, or initials can be used. If img_src contains a URL, it will be used as the avatar image. If img_src contains text, it will generate initials. For more information check https://mantine.dev/core/avatar', 0, 0, 'Image Source');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('avatar'), get_field_id('alt'), 'Avatar', 'Sets the alt text for the avatar image. For more information check https://mantine.dev/core/avatar', 0, 0, 'Alt Text');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('avatar'), get_field_id('mantine_left_icon'), NULL, 'Sets the icon for the avatar. Used only when img_src is empty. Either img_src, icon, or initials can be used. Icon will be displayed as the avatar content. For more information check https://mantine.dev/core/avatar', 0, 0, 'Icon');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_avatar_initials', get_field_type_id('text'), 0, '{"placeholder": "Enter name to generate initials (e.g., Stefan Kod → SK)"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('avatar'), get_field_id('mantine_avatar_initials'), 'U', 'Sets custom text to generate initials for the avatar. Used only when neither img_src nor icon is set. Enter full names (e.g., "Stefan Kod" shows "SK", "John Doe" shows "JD", "test" shows "T"). Either img_src, icon, or initials can be used. For more information check https://mantine.dev/core/avatar', 0, 0, 'Initials');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('avatar'), get_field_id('mantine_variant'), 'light', 'Sets the variant of the avatar. For more information check https://mantine.dev/core/avatar', 0, 0, 'Variant');

-- Use unified size field for avatar
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('avatar'), get_field_id('mantine_size'), 'sm', 'Sets the size of the avatar. For more information check https://mantine.dev/core/avatar', 0, 0, 'Size');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('avatar'), get_field_id('mantine_radius'), '50%', 'Sets the border radius of the avatar. For more information check https://mantine.dev/core/avatar', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('avatar'), get_field_id('mantine_color'), 'blue', 'Sets the color of the avatar. For more information check https://mantine.dev/core/avatar', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('avatar'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Avatar will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/avatar', 0, 1, 'Use Mantine Style');

-- ===========================================
-- BACKGROUND IMAGE COMPONENT
-- ===========================================

-- Add new style 'background-image' based on Mantine background-image component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'background-image',
    
    get_style_group_id('mantine'),
    'Mantine background-image component for background images',
    1
);

-- Add background-image-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('background-image'), get_field_id('img_src'), NULL, 'Sets the background image source. For more information check https://mantine.dev/core/background-image', 0, 0, 'Image Source');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('background-image'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the background image container. For more information check https://mantine.dev/core/background-image', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('background-image'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set background-image will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/background-image', 0, 1, 'Use Mantine Style');

-- ===========================================
-- BADGE COMPONENT
-- ===========================================

-- Add new style 'badge' based on Mantine Badge component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'badge',
    
    get_style_group_id('mantine'),
    'Mantine Badge component for status indicators and labels',
    0
);

-- Add label field for badge
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('badge'), get_field_id('label'), NULL, 'Sets the label for the badge. For more information check https://mantine.dev/core/badge', 0, 0, 'Label');

-- Add Badge-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('badge'), get_field_id('mantine_variant'), 'filled', 'Sets the variant of the badge. For more information check https://mantine.dev/core/badge', 0, 0, 'Variant');

-- Use unified size field for badge
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('badge'), get_field_id('mantine_size'), 'sm', 'Sets the size of the badge. For more information check https://mantine.dev/core/badge', 0, 0, 'Size');

-- Use unified icon fields for badge
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('badge'), get_field_id('mantine_left_icon'), NULL, 'Sets the left section icon for the badge. For more information check https://mantine.dev/core/badge', 0, 0, 'Left Icon');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('badge'), get_field_id('mantine_right_icon'), NULL, 'Sets the right section icon for the badge. For more information check https://mantine.dev/core/badge', 0, 0, 'Right Icon');

-- Add auto contrast field for badge
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('badge'), get_field_id('mantine_auto_contrast'), '0', 'If `autoContrast` prop is set Badge will automatically adjust the contrast of the badge to the background color. For more information check https://mantine.dev/core/badge', 0, 0, 'Auto Contrast');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('badge'), get_field_id('mantine_radius'), 'xl', 'Sets the border radius of the badge. For more information check https://mantine.dev/core/badge', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('badge'), get_field_id('mantine_color'), 'blue', 'Sets the color of the badge. For more information check https://mantine.dev/core/badge', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('badge'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Badge will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/badge', 0, 1, 'Use Mantine Style');

-- Define that accordion-item can ONLY be added inside accordion
INSERT IGNORE INTO styles_allowed_relationships (id_parent_style, id_child_style)
SELECT s1.id, s2.id FROM styles s1, styles s2
WHERE s1.name = 'accordion' AND s2.name = 'accordion-item';

-- ===========================================
-- INDICATOR COMPONENT
-- ===========================================

-- Add new style 'indicator' based on Mantine Indicator component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'indicator',
    
    get_style_group_id('mantine'),
    'Mantine Indicator component for status indicators',
    1
);

-- Add Indicator-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_indicator_processing', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('indicator'), get_field_id('mantine_indicator_processing'), '0', 'If `processing` prop is set, indicator will show processing animation. For more information check https://mantine.dev/core/indicator', 0, 0, 'Processing');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_indicator_disabled', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('indicator'), get_field_id('mantine_indicator_disabled'), '0', 'If `disabled` prop is set, indicator will be disabled. For more information check https://mantine.dev/core/indicator', 0, 0, 'Disabled');

-- Add missing Indicator-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_indicator_size', get_field_type_id('slider'), 0, '{
"options": [
{"value": "6", "text": "6px"},
{"value": "7", "text": "7px"},
{"value": "8", "text": "8px"},
{"value": "9", "text": "9px"},
{"value": "10", "text": "10px"},
{"value": "11", "text": "11px"},
{"value": "12", "text": "12px"},
{"value": "13", "text": "13px"},
{"value": "14", "text": "14px"},
{"value": "15", "text": "15px"},
{"value": "16", "text": "16px"},
{"value": "17", "text": "17px"},
{"value": "18", "text": "18px"},
{"value": "19", "text": "19px"},
{"value": "20", "text": "20px"},
{"value": "21", "text": "21px"},
{"value": "22", "text": "22px"},
{"value": "23", "text": "23px"},
{"value": "24", "text": "24px"},
{"value": "25", "text": "25px"},
{"value": "26", "text": "26px"},
{"value": "27", "text": "27px"},
{"value": "28", "text": "28px"},
{"value": "29", "text": "29px"},
{"value": "30", "text": "30px"},
{"value": "31", "text": "31px"},
{"value": "32", "text": "32px"},
{"value": "33", "text": "33px"},
{"value": "34", "text": "34px"},
{"value": "35", "text": "35px"},
{"value": "36", "text": "36px"},
{"value": "37", "text": "37px"},
{"value": "38", "text": "38px"},
{"value": "39", "text": "39px"},
{"value": "40", "text": "40px"}
]
}');

-- Add indicator-specific size field
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('indicator'), get_field_id('mantine_indicator_size'), '10', 'Sets the size of the indicator in pixels (6-40px). For more information check https://mantine.dev/core/indicator', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('indicator'), get_field_id('mantine_color'), 'red', 'Sets the color of the indicator. For more information check https://mantine.dev/core/indicator', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('indicator'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Indicator will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/indicator', 0, 1, 'Use Mantine Style');


INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_indicator_position', get_field_type_id('select'), 0, '{"searchable": false, "clearable": false, "options":[
{"value":"top-start","text":"Top Start"},
{"value":"top-center","text":"Top Center"},
{"value":"top-end","text":"Top End"},
{"value":"middle-start","text":"Middle Start"},
{"value":"middle-center","text":"Middle Center"},
{"value":"middle-end","text":"Middle End"},
{"value":"bottom-start","text":"Bottom Start"},
{"value":"bottom-center","text":"Bottom Center"},
{"value":"bottom-end","text":"Bottom End"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('indicator'), get_field_id('mantine_indicator_position'), 'top-end', 'Sets the position of the indicator relative to its children. For more information check https://mantine.dev/core/indicator', 0, 0, 'Position');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_indicator_label', get_field_type_id('text'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('indicator'), get_field_id('label'), '', 'Sets the label text displayed in the indicator. For more information check https://mantine.dev/core/indicator', 0, 0, 'Label');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_indicator_inline', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('indicator'), get_field_id('mantine_indicator_inline'), '0', 'If set, the indicator will use inline-block display instead of block. For more information check https://mantine.dev/core/indicator', 0, 0, 'Inline');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_indicator_offset', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": false, "placeholder": "0", "options":[
{"value": "0", "text": "0px"},
{"value": "2", "text": "2px"},
{"value": "4", "text": "4px"},
{"value": "6", "text": "6px"},
{"value": "8", "text": "8px"},
{"value": "10", "text": "10px"},
{"value": "12", "text": "12px"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('indicator'), get_field_id('mantine_indicator_offset'), '0', 'Sets the offset distance of the indicator from its position. Choose from preset values or enter a custom value (e.g., 5, 15, 20). For more information check https://mantine.dev/core/indicator', 0, 0, 'Offset');

-- Removed: mantine_indicator_with_border (replaced with global mantine_border field)

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('indicator'), get_field_id('mantine_border'), '0', 'If set, adds a white border around the indicator. For more information check https://mantine.dev/core/indicator', 0, 0, 'With Border');

-- Reuse existing mantine_radius field for indicator radius
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('indicator'), get_field_id('mantine_radius'), 'xl', 'Sets the border radius of the indicator. For more information check https://mantine.dev/core/indicator', 0, 0, 'Radius');

-- ===========================================
-- KBD COMPONENT
-- ===========================================

-- Add new style 'kbd' based on Mantine Kbd component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'kbd',
    
    get_style_group_id('mantine'),
    'Mantine Kbd component for keyboard key display',
    0
);

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('kbd'), get_field_id('mantine_size'), 'sm', 'Sets the size of the keyboard key. For more information check https://mantine.dev/core/kbd', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('kbd'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Kbd will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/kbd', 0, 1, 'Use Mantine Style');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('kbd'), get_field_id('label'), '', 'Sets the label text displayed in the keyboard key. For more information check https://mantine.dev/core/kbd', 0, 0, 'Label');

-- ===========================================
-- SPOILER COMPONENT
-- ===========================================

-- Add new style 'spoiler' based on Mantine Spoiler component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'spoiler',
    
    get_style_group_id('mantine'),
    'Mantine Spoiler component for collapsible text',
    1
);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('spoiler'), get_field_id('mantine_height'), '200', 'Sets the maximum height before showing the spoiler. For more information check https://mantine.dev/core/spoiler', 0, 0, 'Max Height');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_spoiler_show_label', get_field_type_id('text'), 1, '{"placeholder": "Enter show label"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('spoiler'), get_field_id('mantine_spoiler_show_label'), 'Show more', 'Sets the label for the show button. For more information check https://mantine.dev/core/spoiler', 0, 0, 'Show Label');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_spoiler_hide_label', get_field_type_id('text'), 1, '{"placeholder": "Enter hide label"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('spoiler'), get_field_id('mantine_spoiler_hide_label'), 'Hide', 'Sets the label for the hide button. For more information check https://mantine.dev/core/spoiler', 0, 0, 'Hide Label');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('spoiler'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Spoiler will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/spoiler', 0, 1, 'Use Mantine Style');

-- ===========================================
-- THEME ICON COMPONENT
-- ===========================================

-- Add new style 'theme-icon' based on Mantine ThemeIcon component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'theme-icon',
    
    get_style_group_id('mantine'),
    'Mantine ThemeIcon component for themed icon containers',
    0
);

-- Add ThemeIcon-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('theme-icon'), get_field_id('mantine_variant'), 'filled', 'Sets the variant of the theme icon. For more information check https://mantine.dev/core/theme-icon', 0, 0, 'Variant');

-- Use unified size field for themeIcon
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('theme-icon'), get_field_id('mantine_size'), 'sm', 'Sets the size of the theme icon. For more information check https://mantine.dev/core/theme-icon', 0, 0, 'Size');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('theme-icon'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the theme icon. For more information check https://mantine.dev/core/theme-icon', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('theme-icon'), get_field_id('mantine_color'), 'blue', 'Sets the color of the theme icon. For more information check https://mantine.dev/core/theme-icon', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('theme-icon'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set ThemeIcon will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/theme-icon', 0, 1, 'Use Mantine Style');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('theme-icon'), get_field_id('mantine_left_icon'), NULL, 'Sets the icon for the theme icon. For more information check https://mantine.dev/core/theme-icon', 0, 0, 'Icon');

-- ===========================================
-- TIMELINE COMPONENT
-- ===========================================

-- Add new style 'timeline' based on Mantine Timeline component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'timeline',
    
    get_style_group_id('mantine'),
    'Mantine Timeline component for chronological displays',
    0
);

-- Add Timeline-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_timeline_bullet_size', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"12","text":"12px"},
{"value":"16","text":"16px"},
{"value":"20","text":"20px"},
{"value":"24","text":"24px"},
{"value":"32","text":"32px"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('timeline'), get_field_id('mantine_timeline_bullet_size'), '24', 'Sets the size of the timeline bullets. For more information check https://mantine.dev/core/timeline', 0, 0, 'Bullet Size');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_timeline_line_width', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"1","text":"1px"},
{"value":"2","text":"2px"},
{"value":"3","text":"3px"},
{"value":"4","text":"4px"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('timeline'), get_field_id('mantine_timeline_line_width'), '2', 'Sets the width of the timeline line. For more information check https://mantine.dev/core/timeline', 0, 0, 'Line Width');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_timeline_active', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"-1","text":"None"},
{"value":"0","text":"First item"},
{"value":"1","text":"First two items"},
{"value":"3","text":"First three items"},
{"value":"4","text":"First four items"},
{"value":"5","text":"First five items"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('timeline'), get_field_id('mantine_timeline_active'), '0', 'Index of current active element, all elements before this index will be highlighted with color. For more information check https://mantine.dev/core/timeline', 0, 0, 'Active Index');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_timeline_align', get_field_type_id('segment'), 0, '{"options":[
{"value":"left","text":"Left"},
{"value":"right","text":"Right"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('timeline'), get_field_id('mantine_timeline_align'), 'left', 'Defines line and bullets position relative to content, also sets text-align. For more information check https://mantine.dev/core/timeline', 0, 0, 'Align');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('timeline'), get_field_id('mantine_color'), 'blue', 'Sets the color of the timeline. For more information check https://mantine.dev/core/timeline', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('timeline'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Timeline will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/timeline', 0, 1, 'Use Mantine Style');

-- ===========================================
-- TIMELINE ITEM COMPONENT (child of timeline)
-- ===========================================

-- Add new style 'timeline-item' based on Mantine Timeline.Item component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'timeline-item',
    
    get_style_group_id('mantine'),
    'Mantine Timeline.Item component for individual timeline entries',
    1
);

-- Add Timeline.Item-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('timeline-item'), get_field_id('title'), NULL, 'Sets the title for the timeline item. For more information check https://mantine.dev/core/timeline', 0, 0, 'Title');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_timeline_item_bullet', get_field_type_id('select-icon'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('timeline-item'), get_field_id('mantine_timeline_item_bullet'), NULL, 'Sets the bullet icon for the timeline item. For more information check https://mantine.dev/core/timeline', 0, 0, 'Bullet Icon');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_timeline_item_line_variant', get_field_type_id('select'), 0, '{"searchable": false, "clearable": false, "options":[
{"value":"solid","text":"Solid"},
{"value":"dashed","text":"Dashed"},
{"value":"dotted","text":"Dotted"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('timeline-item'), get_field_id('mantine_timeline_item_line_variant'), 'solid', 'Sets the line variant for the timeline item. For more information check https://mantine.dev/core/timeline', 0, 0, 'Line Variant');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('timeline-item'), get_field_id('mantine_color'), 'gray', 'Sets the color of the timeline item. For more information check https://mantine.dev/core/timeline', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('timeline-item'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Timeline.Item will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/timeline', 0, 1, 'Use Mantine Style');

-- ===========================================
-- BLOCKQUOTE COMPONENT
-- ===========================================

-- Add new style 'blockquote' based on Mantine Blockquote component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'blockquote',
    
    get_style_group_id('mantine'),
    'Mantine Blockquote component for quoted text',
    0
);

-- Add content field for blockquote
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('blockquote'), get_field_id('content'), NULL, 'Sets the content for the blockquote. For more information check https://mantine.dev/core/blockquote', 0, 0, 'Content');

-- Add Blockquote-specific fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('blockquote'), get_field_id('cite'), NULL, 'Sets the citation for the blockquote. For more information check https://mantine.dev/core/blockquote', 0, 0, 'Citation');

-- Use unified icon field for blockquote
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('blockquote'), get_field_id('mantine_left_icon'), NULL, 'Sets the icon for the blockquote. For more information check https://mantine.dev/core/blockquote', 0, 0, 'Icon');

-- Add icon size field for blockquote
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('blockquote'), get_field_id('mantine_icon_size'), '20', 'Sets the size of the blockquote icon in pixels. Choose from preset sizes or enter a custom value. For more information check https://mantine.dev/core/blockquote', 0, 0, 'Icon Size');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('blockquote'), get_field_id('mantine_color'), 'gray', 'Sets the color of the blockquote. For more information check https://mantine.dev/core/blockquote', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('blockquote'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Blockquote will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/blockquote', 0, 1, 'Use Mantine Style');

-- ===========================================
-- CODE COMPONENT
-- ===========================================

-- Add new style 'code' based on Mantine Code component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'code',
    
    get_style_group_id('mantine'),
    'Mantine Code component for inline code display',
    0
);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('code'), get_field_id('content'), NULL, 'Sets the content for the code. For more information check https://mantine.dev/core/code', 0, 0, 'Content');

-- Add Code-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_code_block', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('code'), get_field_id('mantine_code_block'), '0', 'If `block` prop is set, code will be displayed as a block. For more information check https://mantine.dev/core/code', 0, 0, 'Block');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('code'), get_field_id('mantine_color'), 'gray', 'Sets the color of the code. For more information check https://mantine.dev/core/code', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('code'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Code will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/code', 0, 1, 'Use Mantine Style');

-- ===========================================
-- HIGHLIGHT COMPONENT
-- ===========================================

-- Add new style 'highlight' based on Mantine Highlight component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'highlight',
    
    get_style_group_id('mantine'),
    'Mantine Highlight component for text highlighting',
    0
);

-- Add Highlight-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_highlight_highlight', get_field_type_id('text'), 1, NULL);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('highlight'), get_field_id('mantine_highlight_highlight'), 'highlight', 'Sets the text to highlight within the content. This is translatable content that can be different in each language.', 0, 0, 'Highlight Text');

-- Add content field for the main text to be highlighted
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('highlight'), get_field_id('text'), 'Highlight some text in this content', 'The main text content where highlighting will be applied. This is translatable content.', 0, 0, 'Content');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('highlight'), get_field_id('mantine_color'), 'yellow', 'Sets the highlight color. For more information check https://mantine.dev/core/highlight', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('highlight'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Highlight will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/highlight', 0, 1, 'Use Mantine Style');

-- ===========================================
-- TITLE COMPONENT
-- ===========================================

-- Add new style 'title' based on Mantine Title component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'title',
    
    get_style_group_id('mantine'),
    'Mantine Title component for headings and titles',
    0
);

-- Add Title-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_title_order', get_field_type_id('segment'), 0, '{
	"options": [
		{"value": "1", "text": "H1"},
		{"value": "2", "text": "H2"},
		{"value": "3", "text": "H3"},
		{"value": "4", "text": "H4"},
		{"value": "5", "text": "H5"},
		{"value": "6", "text": "H6"}
	]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('title'), get_field_id('mantine_title_order'), '1', 'Sets the heading level (1-6) for the title. For more information check https://mantine.dev/core/title', 0, 0, 'Heading Level');

-- Add textWrap field for Title
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_title_text_wrap', get_field_type_id('segment'), 0, '{
	"options": [
		{"value": "wrap", "text": "Wrap"},
		{"value": "balance", "text": "Balance"},
		{"value": "nowrap", "text": "No Wrap"}
	]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('title'), get_field_id('mantine_title_text_wrap'), 'wrap', 'Sets the text-wrap CSS property for the title. For more information check https://mantine.dev/core/title', 0, 0, 'Text Wrap');

-- Add lineClamp field for Title
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_title_line_clamp', get_field_type_id('select'), 0, '{
	"creatable": true,
	"searchable": false,
	"clearable": true,
	"placeholder": "3",
	"options": [
		{"value": "1", "text": "1 line"},
		{"value": "2", "text": "2 lines"},
		{"value": "3", "text": "3 lines"},
		{"value": "4", "text": "4 lines"},
		{"value": "5", "text": "5 lines"}
	]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('title'), get_field_id('mantine_title_line_clamp'), NULL, 'Sets the number of lines after which the text will be truncated. For more information check https://mantine.dev/core/title', 0, 0, 'Line Clamp');

-- Add translatable content field for Title
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('title'), get_field_id('content'), NULL, 'The text content of the title. This field supports multiple languages.', 0, 0, 'Content');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('title'), get_field_id('mantine_size'), 'lg', 'Sets the size of the title. For more information check https://mantine.dev/core/title', 0, 0, 'Size');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('title'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Title will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/title', 0, 1, 'Use Mantine Style');

-- ===========================================
-- LIST COMPONENTS
-- ===========================================

-- Add new style 'list' based on Mantine List component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'list',
    
    get_style_group_id('mantine'),
    'Mantine List component for displaying ordered or unordered lists',
    0
);

-- Add new style 'list-item' based on Mantine List.Item component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'list-item',
    
    get_style_group_id('mantine'),
    'Mantine List.Item component for individual list items',
    1
);

-- Create list-specific fields if they don't exist
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_list_list_style_type', get_field_type_id('select'), 0, '{
  "options": [
    {"value": "disc", "text": "Disc (●)"},
    {"value": "circle", "text": "Circle (○)"},
    {"value": "square", "text": "Square (■)"},
    {"value": "decimal", "text": "Decimal (1, 2, 3)"},
    {"value": "decimal-leading-zero", "text": "Decimal Leading Zero (01, 02, 03)"},
    {"value": "lower-alpha", "text": "Lower Alpha (a, b, c)"},
    {"value": "upper-alpha", "text": "Upper Alpha (A, B, C)"},
    {"value": "lower-roman", "text": "Lower Roman (i, ii, iii)"},
    {"value": "upper-roman", "text": "Upper Roman (I, II, III)"},
    {"value": "none", "text": "None"}
  ]
}'),
(NULL, 'mantine_list_item_content', get_field_type_id('textarea'), 1, null),
(NULL, 'mantine_list_with_padding', get_field_type_id('checkbox'), 0, null),
(NULL, 'mantine_list_center', get_field_type_id('checkbox'), 0, null),
(NULL, 'mantine_list_icon', get_field_type_id('select-icon'), 0, null),
(NULL, 'mantine_list_item_icon', get_field_type_id('select-icon'), 0, null);

-- Link fields to list style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('list'), get_field_id('mantine_list_list_style_type'), 'disc', 'Sets custom bullet style for the list (e.g., "disc", "circle", "square", "decimal", "lower-alpha"). For more information check https://mantine.dev/core/list', 0, 0, 'List Style Type'),
(get_style_id('list'), get_field_id('mantine_list_with_padding'), '0', 'If set, adds padding to nested lists for better hierarchy. For more information check https://mantine.dev/core/list', 0, 0, 'With Padding'),
(get_style_id('list'), get_field_id('mantine_list_center'), '0', 'If set, centers the list item content with the icon. For more information check https://mantine.dev/core/list', 0, 0, 'Center Content'),
(get_style_id('list'), get_field_id('mantine_list_icon'), NULL, 'Sets the default icon for all list items. For more information check https://mantine.dev/core/list', 0, 0, 'Default Icon'),
(get_style_id('list'), get_field_id('mantine_size'), 'sm', 'Sets the size of the list. For more information check https://mantine.dev/core/list', 0, 0, 'Size'),
(get_style_id('list'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the list component', 0, 1, 'Use Mantine Style');

-- Link fields to list-item style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('list-item'), get_field_id('mantine_list_item_content'), NULL, 'The content text for this list item', 0, 0, 'Content'),
(get_style_id('list-item'), get_field_id('mantine_list_item_icon'), NULL, 'Sets the icon for this list item, overrides the parent list icon. For more information check https://mantine.dev/core/list', 0, 0, 'Item Icon'),
(get_style_id('list-item'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the list item component', 0, 1, 'Use Mantine Style');

-- Define parent-child relationship: list can contain list-item
INSERT IGNORE INTO styles_allowed_relationships (id_parent_style, id_child_style)
SELECT s1.id, s2.id FROM styles s1, styles s2
WHERE s1.name = 'list' AND s2.name = 'list-item';


-- ===========================================
-- TYPOGRAPHY COMPONENT
-- ===========================================

-- Add new style 'typography' based on Mantine Typography component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'typography',
    
    get_style_group_id('mantine'),
    'Mantine Typography component for consistent typography styles',
    1
);

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('typography'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Typography will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/typography', 0, 1, 'Use Mantine Style');

-- Define that timeline-item can ONLY be added inside timeline
INSERT IGNORE INTO styles_allowed_relationships (id_parent_style, id_child_style)
SELECT s1.id, s2.id FROM styles s1, styles s2
WHERE s1.name = 'timeline' AND s2.name = 'timeline-item';

INSERT IGNORE INTO styles_allowed_relationships (id_parent_style, id_child_style)
SELECT s1.id, s2.id FROM styles s1, styles s2
WHERE s1.name = 'tabs' AND s2.name = 'tab';

-- ===========================================
-- DIVIDER COMPONENT
-- ===========================================

-- Add new style 'divider' based on Mantine Divider component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'divider',
    
    get_style_group_id('mantine'),
    'Mantine Divider component for visual separation',
    0
);

-- Add Divider-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_divider_variant', get_field_type_id('select'), 0, '{"searchable": false, "clearable": false, "options":[
{"value":"solid","text":"Solid"},
{"value":"dashed","text":"Dashed"},
{"value":"dotted","text":"Dotted"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('divider'), get_field_id('mantine_divider_variant'), 'solid', 'Sets the variant of the divider line. For more information check https://mantine.dev/core/divider', 0, 0, 'Variant');

-- Use unified orientation field for divider
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('divider'), get_field_id('mantine_orientation'), 'horizontal', 'Sets the orientation of the divider. For more information check https://mantine.dev/core/divider', 0, 0, 'Orientation');

-- Use unified mantine_size slider field for divider thickness
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('divider'), get_field_id('mantine_size'), 'sm', 'Sets the thickness of the divider line. For more information check https://mantine.dev/core/divider', 0, 0, 'Size');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_divider_label', get_field_type_id('text'), 1, '{"placeholder": "Divider label"}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('divider'), get_field_id('mantine_divider_label'), NULL, 'Sets the label text for the divider. For more information check https://mantine.dev/core/divider', 0, 0, 'Label');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_divider_label_position', get_field_type_id('select'), 0, '{"searchable": false, "clearable": false, "options":[
{"value":"left","text":"Left"},
{"value":"center","text":"Center"},
{"value":"right","text":"Right"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('divider'), get_field_id('mantine_divider_label_position'), 'center', 'Sets the position of the divider label. For more information check https://mantine.dev/core/divider', 0, 0, 'Label Position');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('divider'), get_field_id('mantine_color'), 'gray', 'Sets the color of the divider. For more information check https://mantine.dev/core/divider', 0, 0, 'Color');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('divider'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Divider will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/divider', 0, 1, 'Use Mantine Style');

-- ===========================================
-- PAPER COMPONENT
-- ===========================================

-- Add new style 'paper' based on Mantine Paper component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'paper',
    
    get_style_group_id('mantine'),
    'Mantine Paper component for elevated surfaces',
    1
);

-- Add Paper-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_paper_shadow', get_field_type_id('slider'), 0, '{
"options": [
{"value": "none", "text": "None"},
{"value": "xs", "text": "Extra Small"},
{"value": "sm", "text": "Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value": "xl", "text": "Extra Large"}
]
}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('paper'), get_field_id('mantine_paper_shadow'), 'sm', 'Sets the shadow of the paper. For more information check https://mantine.dev/core/paper', 0, 0, 'Shadow');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('paper'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the paper. For more information check https://mantine.dev/core/paper', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('paper'), get_field_id('mantine_px'), NULL, 'Sets the horizontal padding of the paper. For more information check https://mantine.dev/core/paper', 0, 0, 'Horizontal Padding');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('paper'), get_field_id('mantine_py'), NULL, 'Sets the vertical padding of the paper. For more information check https://mantine.dev/core/paper', 0, 0, 'Vertical Padding');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('paper'), get_field_id('mantine_border'), '0', 'If set, the paper will have a border. For more information check https://mantine.dev/core/paper', 0, 0, 'With Border');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('paper'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set Paper will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/paper', 0, 1, 'Use Mantine Style');

-- ===========================================
-- scroll-area COMPONENT
-- ===========================================

-- Add new style 'scroll-area' based on Mantine scroll-area component
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'scroll-area',
    
    get_style_group_id('mantine'),
    'Mantine scroll-area component for custom scrollbars',
    1
);

-- Add scroll-area-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_scroll_area_scrollbar_size', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "options":[
{"value":"6","text":"6px"},
{"value":"8","text":"8px"},
{"value":"10","text":"10px"},
{"value":"12","text":"12px"},
{"value":"16","text":"16px"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('scroll-area'), get_field_id('mantine_scroll_area_scrollbar_size'), '8', 'Sets the size of the scrollbar. For more information check https://mantine.dev/core/scroll-area', 0, 0, 'Scrollbar Size');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_scroll_area_type', get_field_type_id('segment'), 0, '{"options":[
{"value":"hover","text":"Hover"},
{"value":"always","text":"Always"},
{"value":"never","text":"Never"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('scroll-area'), get_field_id('mantine_scroll_area_type'), 'hover', 'Sets when to show the scrollbar. For more information check https://mantine.dev/core/scroll-area', 0, 0, 'Scrollbar Type');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_scroll_area_offset_scrollbars', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('scroll-area'), get_field_id('mantine_scroll_area_offset_scrollbars'), '0', 'If `offsetScrollbars` prop is set, scrollbars will be offset from the container edge. For more information check https://mantine.dev/core/scroll-area', 0, 0, 'Offset Scrollbars');

-- Reuse existing fields
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('scroll-area'), get_field_id('mantine_height'), NULL, 'Sets the height of the scroll area. For more information check https://mantine.dev/core/scroll-area', 0, 0, 'Height');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('scroll-area'), get_field_id('use_mantine_style'), 1, 'If `useMantineStyle` prop is set scroll-area will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/scroll-area', 0, 1, 'Use Mantine Style');

-- Add scroll hide delay field for scroll-area
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_scroll_area_scroll_hide_delay', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "placeholder": "1000", "options":[
{"value":"0","text":"Instant (0ms)"},
{"value":"300","text":"Very Fast (300ms)"},
{"value":"500","text":"Fast (500ms)"},
{"value":"1000","text":"Normal (1000ms)"},
{"value":"1500","text":"Slow (1500ms)"},
{"value":"2000","text":"Very Slow (2000ms)"},
{"value":"3000","text":"Extra Slow (3000ms)"}
]}');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('scroll-area'), get_field_id('mantine_scroll_area_scroll_hide_delay'), '1000', 'Sets the delay in milliseconds before hiding scrollbars after scrolling stops. Only applies when scrollbar type is hover. For more information check https://mantine.dev/core/scroll-area', 0, 0, 'Scroll Hide Delay');

-- ===========================================
-- CARD STYLE
-- ===========================================

-- Add card style
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'card',
    
    get_style_group_id('mantine'),
    'Card container component with Mantine styling',
    1
);

-- Create card-specific fields if they don't exist
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_card_shadow', get_field_type_id('slider'), 0, '{
"options": [
{"value": "none", "text": "None"},
{"value": "xs", "text": "Extra Small"},
{"value": "sm", "text": "Small"},
{"value": "md", "text": "Medium"},
{"value": "lg", "text": "Large"},
{"value": "xl", "text": "Extra Large"}
]
}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_card_padding', get_field_type_id('slider'), 0, '{
"options": [
{"value": "none", "text": "None"},
{"value": "xs", "text": "Extra Small"},
{"value": "sm", "text": "Small"},
{"value": "md", "text": "Medium"},
{"value": "lg", "text": "Large"},
{"value": "xl", "text": "Extra Large"}
]
}');

-- Removed: mantine_card_with_border (replaced with global mantine_border field)

-- Add card-segment style for child components
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'card-segment',
    
    get_style_group_id('mantine'),
    'Card segment component for organizing card content',
    1
);

-- Link fields to card style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('card'), get_field_id('use_mantine_style'), '1', 'If `useMantineStyle` prop is set Card will use the Mantine style, otherwise it will be a clear element which can be styled with CSS and Tailwind CSS classes. For more information check https://mantine.dev/core/card', 0, 1, 'Use Mantine Style');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('card'), get_field_id('mantine_card_shadow'), 'sm', 'Sets the shadow of the card. For more information check https://mantine.dev/core/card', 0, 0, 'Shadow');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('card'), get_field_id('mantine_card_padding'), 'sm', 'Sets the padding of the card. For more information check https://mantine.dev/core/card', 0, 0, 'Padding');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('card'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the card. For more information check https://mantine.dev/core/card', 0, 0, 'Radius');

INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`)
VALUES (get_style_id('card'), get_field_id('mantine_border'), '0', 'If set, the card will have a border. For more information check https://mantine.dev/core/card', 0, 0, 'With Border');

-- Card can contain Card-Segment
INSERT IGNORE INTO styles_allowed_relationships (id_parent_style, id_child_style)
SELECT s1.id, s2.id FROM styles s1, styles s2
WHERE s1.name = 'card' AND s2.name = 'card-segment';

-- ===========================================
-- FIELD OPTIMIZATION SUMMARY AND FINAL CLEANUP
-- ===========================================

-- All field definitions have been properly structured with:
-- ✅ Fields inserted BEFORE they are used in styles_fields
-- ✅ Translatable fields properly marked with display = 1
-- ✅ JSON textarea fields for flexible option configuration
-- ✅ Unified field usage across all components
-- ✅ No lazy UPDATE statements - everything fixed directly in INSERT statements

-- ===========================================
-- UNIFIED FIELDS CREATED (INSERTED FIRST):
-- ===========================================
-- 1. mantine_size - unified size field (xs, sm, md, lg, xl) - used by ALL 30+ components
-- 2. mantine_radius - unified radius field (xs, sm, md, lg, xl) - used by ALL components
-- 3. mantine_left_icon - unified left icon field - used by button, tab, badge, blockquote
-- 4. mantine_right_icon - unified right icon field - used by button, tab
-- 5. mantine_orientation - unified orientation field (horizontal/vertical) - used by radio, segmented-control, tabs
-- 6. mantine_color_format - unified color format field (hex/rgba/hsla) - used by color-input, color-picker
-- 7. mantine_numeric_min - unified numeric min field - used by numberInput, range-slider
-- 8. mantine_numeric_max - unified numeric max field - used by numberInput, range-slider
-- 9. mantine_numeric_step - unified numeric step field - used by numberInput, range-slider

-- ===========================================
-- TRANSLATABLE TEXT INPUT FIELDS (display = 1):
-- ===========================================
-- 1. mantine_switch_on_label - Text input for switch on label
-- 2. mantine_switch_off_label - Text input for switch off label
-- Accordion item value field removed - handled in frontend
-- 4. mantine_spoiler_show_label - Text input for spoiler show label
-- 5. mantine_spoiler_hide_label - Text input for spoiler hide label

-- ===========================================
-- TRANSLATABLE JSON TEXTAREA FIELDS (display = 1):
-- ===========================================
-- 1. mantine_radio_options - JSON textarea for radio group options
-- 2. mantine_segmented_control_data - JSON textarea for segmented control options
-- 3. mantine_combobox_data - JSON textarea for combobox options
-- 4. mantine_multi_select_data - JSON textarea for multi-select options

-- ===========================================
-- NAMING CONVENTION UPDATES:
-- ===========================================
-- 1. timelineItem → timeline-item (consistent kebab-case naming)
-- 2. accordionItem → accordion-item (consistent kebab-case naming)
-- 5. accordionPanel component removed (handled in frontend)
-- 6. All component names follow kebab-case pattern for consistency

-- ===========================================
-- FIELD ORDERING IMPROVEMENTS:
-- ===========================================
-- ✅ All field definitions are INSERTED BEFORE they are used in styles_fields
-- ✅ No more lazy UPDATE statements at the end
-- ✅ Proper SQL execution order maintained
-- ✅ All display values set correctly in INSERT statements

-- ===========================================
-- OPTIMIZATION RESULTS:
-- ===========================================
-- PROGRESS COMPONENT STYLES
-- ===========================================

-- Add progress-root style (compound progress component)
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'progress-root',
    
    get_style_group_id('mantine'),
    'Mantine Progress.Root component for compound progress bars with multiple sections',
    0
);

-- Add progress style (basic progress component)
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'progress',
    
    get_style_group_id('mantine'),
    'Mantine Progress component for basic progress bars',
    0
);

-- Add progress-section style (section within compound progress)
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'progress-section',
    
    get_style_group_id('mantine'),
    'Mantine Progress.Section component for individual progress sections',
    0
);

-- Link fields to progress-root style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('progress-root'), get_field_id('mantine_size'), 'sm', 'Sets the size of the progress bar. Choose from preset sizes or enter a custom value. For more information check https://mantine.dev/core/progress', 0, 0, 'Size'),
(get_style_id('progress-root'), get_field_id('mantine_progress_auto_contrast'), '0', 'If set, colors will be adjusted for better contrast. For more information check https://mantine.dev/core/progress', 0, 0, 'Auto Contrast'),
(get_style_id('progress-root'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the progress component', 0, 1, 'Use Mantine Style');

-- Link fields to progress style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('progress'), get_field_id('value'), '0', 'Sets the progress value (0-100). For more information check https://mantine.dev/core/progress', 0, 0, 'Progress Value'),
(get_style_id('progress'), get_field_id('mantine_color'), 'blue', 'Sets the color of the progress bar. For more information check https://mantine.dev/core/progress', 0, 0, 'Color'),
(get_style_id('progress'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the progress bar. For more information check https://mantine.dev/core/progress', 0, 0, 'Radius'),
(get_style_id('progress'), get_field_id('mantine_size'), 'sm', 'Sets the size of the progress bar. Choose from preset sizes or enter a custom value. For more information check https://mantine.dev/core/progress', 0, 0, 'Size'),
(get_style_id('progress'), get_field_id('mantine_progress_striped'), '0', 'If set, displays stripes on the progress bar. For more information check https://mantine.dev/core/progress', 0, 0, 'Striped'),
(get_style_id('progress'), get_field_id('mantine_progress_animated'), '0', 'If set, animates the progress bar stripes. For more information check https://mantine.dev/core/progress', 0, 0, 'Animated'),
(get_style_id('progress'), get_field_id('mantine_progress_transition_duration'), '200', 'Sets the transition duration in milliseconds. Choose from preset durations or enter a custom value. For more information check https://mantine.dev/core/progress', 0, 0, 'Transition Duration'),
(get_style_id('progress'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the progress component', 0, 1, 'Use Mantine Style');

-- Link fields to progress-section style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('progress-section'), get_field_id('value'), '0', 'Sets the value for this progress section (0-100). For more information check https://mantine.dev/core/progress', 0, 0, 'Section Value'),
(get_style_id('progress-section'), get_field_id('mantine_color'), 'blue', 'Sets the color of this progress section. For more information check https://mantine.dev/core/progress', 0, 0, 'Color'),
(get_style_id('progress-section'), get_field_id('mantine_progress_striped'), '0', 'If set, displays stripes on this progress section. For more information check https://mantine.dev/core/progress', 0, 0, 'Striped'),
(get_style_id('progress-section'), get_field_id('mantine_progress_animated'), '0', 'If set, animates this progress section stripes. For more information check https://mantine.dev/core/progress', 0, 0, 'Animated'),
(get_style_id('progress-section'), get_field_id('label'), NULL, 'Sets the label text for this progress section. For more information check https://mantine.dev/core/progress', 0, 0, 'Label'),
(get_style_id('progress-section'), get_field_id('mantine_tooltip_label'), NULL, 'Sets the tooltip text for this progress section. Leave empty to disable tooltip. For more information check https://mantine.dev/core/tooltip', 0, 0, 'Tooltip Label'),
(get_style_id('progress-section'), get_field_id('mantine_tooltip_position'), 'top', 'Sets the position of the tooltip. For more information check https://mantine.dev/core/tooltip', 0, 0, 'Tooltip Position'),
(get_style_id('progress-section'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the progress section component', 0, 1, 'Use Mantine Style');

-- Define parent-child relationships for progress components
-- progress-root can contain progress-section
INSERT IGNORE INTO styles_allowed_relationships (id_parent_style, id_child_style)
SELECT s1.id, s2.id FROM styles s1, styles s2
WHERE s1.name = 'progress-root' AND s2.name = 'progress-section';

-- ===========================================
-- TEXT COMPONENT DEFINITION
-- ===========================================

-- Create Text-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_text_font_weight', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "placeholder": "400", "options": [{"value": "100", "text": "Thin (100)"}, {"value": "200", "text": "Extra Light (200)"}, {"value": "300", "text": "Light (300)"}, {"value": "400", "text": "Regular (400)"}, {"value": "500", "text": "Medium (500)"}, {"value": "600", "text": "Semi Bold (600)"}, {"value": "700", "text": "Bold (700)"}, {"value": "800", "text": "Extra Bold (800)"}, {"value": "900", "text": "Black (900)"}]}'),
(NULL, 'mantine_text_font_style', get_field_type_id('segment'), 0, '{"options": [{"value": "normal", "text": "Normal"}, {"value": "italic", "text": "Italic"}]}'),
(NULL, 'mantine_text_text_decoration', get_field_type_id('segment'), 0, '{"options": [{"value": "none", "text": "None"}, {"value": "underline", "text": "Underline"}, {"value": "line-through", "text": "Strikethrough"}]}'),
(NULL, 'mantine_text_text_transform', get_field_type_id('segment'), 0, '{"options": [{"value": "none", "text": "None"}, {"value": "uppercase", "text": "Uppercase"}, {"value": "capitalize", "text": "Capitalize"}, {"value": "lowercase", "text": "Lowercase"}]}'),
(NULL, 'mantine_text_align', get_field_type_id('segment'), 0, '{"options": [{"value": "left", "text": "Left"}, {"value": "center", "text": "Center"}, {"value": "right", "text": "Right"}, {"value": "justify", "text": "Justify"}]}'),
(NULL, 'mantine_text_variant', get_field_type_id('segment'), 0, '{"options": [{"value": "default", "text": "Default"}, {"value": "gradient", "text": "Gradient"}]}'),
(NULL, 'mantine_text_gradient', get_field_type_id('textarea'), 0, NULL),
(NULL, 'mantine_text_truncate', get_field_type_id('segment'), 0, '{"options": [{"value": "none", "text": "None"}, {"value": "end", "text": "End"}, {"value": "start", "text": "Start"}]}'),
(NULL, 'mantine_text_line_clamp', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "placeholder": "3", "options": [{"value": "2", "text": "2 lines"}, {"value": "3", "text": "3 lines"}, {"value": "4", "text": "4 lines"}, {"value": "5", "text": "5 lines"}]}'),
(NULL, 'mantine_text_inherit', get_field_type_id('checkbox'), 0, null),
(NULL, 'mantine_text_span', get_field_type_id('checkbox'), 0, null);

-- Add text style
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'text',
    
    get_style_group_id('mantine'),
    'Mantine Text component for displaying text with various styling options',
    0
);

-- Link fields to text style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('text'), get_field_id('text'), NULL, 'The text content to display. For more information check https://mantine.dev/core/text', 0, 0, 'Text Content'),
(get_style_id('text'), get_field_id('mantine_size'), 'sm', 'Sets the font size of the text. For more information check https://mantine.dev/core/text', 0, 0, 'Size'),
(get_style_id('text'), get_field_id('mantine_color'), '', 'Sets the color of the text. For more information check https://mantine.dev/core/text', 0, 0, 'Color'),
(get_style_id('text'), get_field_id('mantine_text_font_weight'), NULL, 'Sets the font weight of the text. Choose from preset weights or enter a custom value (100-900). For more information check https://mantine.dev/core/text', 0, 0, 'Font Weight'),
(get_style_id('text'), get_field_id('mantine_text_font_style'), 'normal', 'Sets the font style of the text. For more information check https://mantine.dev/core/text', 0, 0, 'Font Style'),
(get_style_id('text'), get_field_id('mantine_text_text_decoration'), 'none', 'Sets the text decoration of the text. For more information check https://mantine.dev/core/text', 0, 0, 'Text Decoration'),
(get_style_id('text'), get_field_id('mantine_text_text_transform'), 'none', 'Sets the text transform of the text. For more information check https://mantine.dev/core/text', 0, 0, 'Text Transform'),
(get_style_id('text'), get_field_id('mantine_text_align'), 'left', 'Sets the text alignment. For more information check https://mantine.dev/core/text', 0, 0, 'Text Align'),
(get_style_id('text'), get_field_id('mantine_text_variant'), 'default', 'Sets the text variant. Use "gradient" for gradient text. For more information check https://mantine.dev/core/text', 0, 0, 'Variant'),
(get_style_id('text'), get_field_id('mantine_text_gradient'), NULL, 'Sets the gradient configuration for gradient variant. Only used when variant is "gradient". Format: {"from": "blue", "to": "cyan", "deg": 90}. For more information check https://mantine.dev/core/text', 0, 0, 'Gradient'),
(get_style_id('text'), get_field_id('mantine_text_truncate'), NULL, 'Truncates the text with ellipsis. For more information check https://mantine.dev/core/text', 0, 0, 'Truncate'),
(get_style_id('text'), get_field_id('mantine_text_line_clamp'), NULL, 'Limits the number of lines to display. Choose from preset values or enter a custom number. For more information check https://mantine.dev/core/text', 0, 0, 'Line Clamp'),
(get_style_id('text'), get_field_id('mantine_text_inherit'), '0', 'If set, Text will inherit parent styles (font-size, font-family, line-height). For more information check https://mantine.dev/core/text', 0, 0, 'Inherit'),
(get_style_id('text'), get_field_id('mantine_text_span'), '0', 'If set, Text will render as a span element instead of p. For more information check https://mantine.dev/core/text', 0, 0, 'Span'),
(get_style_id('text'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the text component', 0, 1, 'Use Mantine Style');

-- ===========================================
-- CAROUSEL COMPONENT DEFINITION
-- ===========================================

-- Create general fields for carousel and other components
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'loop', get_field_type_id('checkbox'), 0, null),
(NULL, 'drag_free', get_field_type_id('checkbox'), 0, null),
(NULL, 'skip_snaps', get_field_type_id('checkbox'), 0, null);

-- Create Carousel-specific fields (using global fields where possible)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_carousel_slide_size', get_field_type_id('slider'), 0, '{"min": 10, "max": 100, "step": 5, "defaultValue": 100, "marks": [{"value": 25, "label": "25%", "saveValue": "25"}, {"value": 50, "label": "50%", "saveValue": "50"}, {"value": 75, "label": "75%", "saveValue": "75"}, {"value": 100, "label": "100%", "saveValue": "100"}]}'),
(NULL, 'mantine_carousel_slide_gap', get_field_type_id('slider'), 0, '{ "options": [{"value": "xs", "text": "Extra Small"}, {"value": "sm", "text": "Small"}, {"value":"md","text":"Medium"}, {"value":"lg","text":"Large"}, {"value": "xl", "text": "Extra Large"}]}'),
(NULL, 'mantine_carousel_controls_offset', get_field_type_id('slider'), 0, '{ "options": [{"value": "xs", "text": "Extra Small"}, {"value": "sm", "text": "Small"}, {"value":"md","text":"Medium"}, {"value":"lg","text":"Large"}, {"value": "xl", "text": "Extra Large"}]}'),
(NULL, 'mantine_carousel_next_control_icon', get_field_type_id('select-icon'), 0, null),
(NULL, 'mantine_carousel_previous_control_icon', get_field_type_id('select-icon'), 0, null),
(NULL, 'mantine_carousel_align', get_field_type_id('segment'), 0, '{"options": [{"value": "start", "text": "Start"}, {"value": "center", "text": "Center"}, {"value": "end", "text": "End"}]}'),
(NULL, 'mantine_carousel_contain_scroll', get_field_type_id('segment'), 0, '{"options": [{"value": "auto", "text": "Auto"}, {"value": "trimSnaps", "text": "Trim Snaps"}, {"value": "keepSnaps", "text": "Keep Snaps"}]}'),
(NULL, 'mantine_carousel_in_view_threshold', get_field_type_id('slider'), 0, '{"min": 0, "max": 1, "step": 0.1, "defaultValue": 0, "marks": [{"value": 0, "label": "0%", "saveValue": "0"}, {"value": 0.5, "label": "50%", "saveValue": "0.5"}, {"value": 1, "label": "100%", "saveValue": "1"}]}'),
(NULL, 'mantine_carousel_duration', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "placeholder": "25", "options": [{"value": "10", "text": "Fast (10ms)"}, {"value": "25", "text": "Normal (25ms)"}, {"value": "50", "text": "Slow (50ms)"}, {"value": "100", "text": "Very Slow (100ms)"}, {"value": "150", "text": "Extra Slow (150ms)"}, {"value": "200", "text": "Super Slow (200ms)"}, {"value": "0", "text": "Instant (0ms)"}]}'),
(NULL, 'mantine_carousel_embla_options', get_field_type_id('json'), 0, NULL);

-- Add carousel style
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'carousel',
    
    get_style_group_id('mantine'),
    'Mantine Carousel component for displaying content in a slideshow format',
    1
);


-- Link fields to carousel style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('carousel'), get_field_id('mantine_height'), NULL, 'Sets the height of the carousel. Choose from preset values or enter a custom value. For more information check https://mantine.dev/x/carousel', 0, 0, 'Height'),
(get_style_id('carousel'), get_field_id('mantine_carousel_slide_size'), '100', 'Sets the size of each slide as a percentage. Use the slider to adjust from 10% to 100%. For more information check https://mantine.dev/x/carousel', 0, 0, 'Slide Size'),
(get_style_id('carousel'), get_field_id('mantine_carousel_slide_gap'), 'sm', 'Sets the gap between slides. Choose from preset sizes or enter a custom value. For more information check https://mantine.dev/x/carousel', 0, 0, 'Slide Gap'),
(get_style_id('carousel'), get_field_id('mantine_orientation'), 'horizontal', 'Sets the orientation of the carousel. For more information check https://mantine.dev/x/carousel', 0, 0, 'Orientation'),
(get_style_id('carousel'), get_field_id('has_controls'), '1', 'If set, displays navigation controls (previous/next buttons). For more information check https://mantine.dev/x/carousel', 0, 0, 'Show Controls'),
(get_style_id('carousel'), get_field_id('has_indicators'), '1', 'If set, displays slide indicators at the bottom. For more information check https://mantine.dev/x/carousel', 0, 0, 'Show Indicators'),
(get_style_id('carousel'), get_field_id('mantine_control_size'), '26', 'Sets the size of the navigation controls in pixels. Use the slider to adjust from 14px to 40px. For more information check https://mantine.dev/x/carousel', 0, 0, 'Control Size'),
(get_style_id('carousel'), get_field_id('mantine_carousel_controls_offset'), 'sm', 'Sets the offset of the navigation controls from the carousel edges. Choose from preset sizes or enter a custom value. For more information check https://mantine.dev/x/carousel', 0, 0, 'Controls Offset'),
(get_style_id('carousel'), get_field_id('mantine_carousel_next_control_icon'), NULL, 'Sets the icon for the next control button. For more information check https://mantine.dev/x/carousel', 0, 0, 'Next Control Icon'),
(get_style_id('carousel'), get_field_id('mantine_carousel_previous_control_icon'), NULL, 'Sets the icon for the previous control button. For more information check https://mantine.dev/x/carousel', 0, 0, 'Previous Control Icon'),
(get_style_id('carousel'), get_field_id('mantine_loop'), '0', 'If set, enables infinite loop navigation. For more information check https://mantine.dev/x/carousel', 0, 0, 'Loop'),
(get_style_id('carousel'), get_field_id('drag_free'), '0', 'If set, disables slide snap points allowing free dragging. For more information check https://mantine.dev/x/carousel', 0, 0, 'Drag Free'),
(get_style_id('carousel'), get_field_id('mantine_carousel_align'), 'start', 'Sets the alignment of slides. For more information check https://mantine.dev/x/carousel', 0, 0, 'Align'),
(get_style_id('carousel'), get_field_id('mantine_carousel_contain_scroll'), 'trimSnaps', 'Sets the contain scroll behavior. For more information check https://mantine.dev/x/carousel', 0, 0, 'Contain Scroll'),
(get_style_id('carousel'), get_field_id('skip_snaps'), '0', 'If set, allows skipping slides without snapping to them. For more information check https://mantine.dev/x/carousel', 0, 0, 'Skip Snaps'),
(get_style_id('carousel'), get_field_id('mantine_carousel_in_view_threshold'), '0', 'Sets the threshold for slide visibility detection (0-1). Use the slider to adjust the percentage. For more information check https://mantine.dev/x/carousel', 0, 0, 'In View Threshold'),
(get_style_id('carousel'), get_field_id('mantine_carousel_duration'), '25', 'Sets the transition duration in milliseconds. Choose from preset durations or enter a custom value. For more information check https://mantine.dev/x/carousel', 0, 0, 'Duration'),
(get_style_id('carousel'), get_field_id('mantine_carousel_embla_options'), NULL, 'Sets advanced Embla carousel options as JSON. For more information check https://www.embla-carousel.com/api/options/', 0, 0, 'Embla Options'),
(get_style_id('carousel'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the carousel component', 0, 1, 'Use Mantine Style');

-- ===========================================
-- CHECKBOX COMPONENT DEFINITION
-- ===========================================

-- Create Checkbox-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_checkbox_icon', get_field_type_id('select-icon'), 0, null),
(NULL, 'mantine_checkbox_labelPosition', get_field_type_id('segment'), 0, '{"options":[{"value":"right","text":"Right"},{"value":"left","text":"Left"}]}');

-- Add checkbox style
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'checkbox',
    
    get_style_group_id('mantine'),
    'Mantine Checkbox component for boolean input with customizable styling',
    0
);

-- Link fields to checkbox style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('checkbox'), get_field_id('label'), NULL, 'Sets the label text displayed next to the checkbox. For more information check https://mantine.dev/core/checkbox', 0, 0, 'Label'),
(get_style_id('checkbox'), get_field_id('name'), NULL, 'Sets the name attribute for the checkbox input. For more information check https://mantine.dev/core/checkbox', 0, 0, 'Name'),
(get_style_id('checkbox'), get_field_id('value'), NULL, 'Sets the value attribute for the checkbox input. For more information check https://mantine.dev/core/checkbox', 0, 0, 'Value'),
(get_style_id('checkbox'), get_field_id('checkbox_value'), '1', 'Sets the checkbox value when checked. For more information check https://mantine.dev/core/checkbox', 0, 0, 'Checkbox Value'),
(get_style_id('checkbox'), get_field_id('is_required'), '0', 'If set, the checkbox will be required for form submission. For more information check https://mantine.dev/core/checkbox', 0, 0, 'Required'),
(get_style_id('checkbox'), get_field_id('disabled'), '0', 'If set, the checkbox will be disabled. For more information check https://mantine.dev/core/checkbox', 0, 0, 'Disabled'),
(get_style_id('checkbox'), get_field_id('description'), NULL, 'Sets the description text displayed below the label. For more information check https://mantine.dev/core/checkbox', 0, 0, 'Description'),
(get_style_id('checkbox'), get_field_id('mantine_size'), 'sm', 'Sets the size of the checkbox. Choose from preset sizes (xs, sm, md, lg, xl). For more information check https://mantine.dev/core/checkbox', 0, 0, 'Size'),
(get_style_id('checkbox'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the checkbox. Choose from preset values or enter a custom value. For more information check https://mantine.dev/core/checkbox', 0, 0, 'Radius'),
(get_style_id('checkbox'), get_field_id('mantine_color'), NULL, 'Sets the color of the checkbox. Choose from theme colors or enter a custom color. For more information check https://mantine.dev/core/checkbox', 0, 0, 'Color'),
(get_style_id('checkbox'), get_field_id('mantine_checkbox_icon'), NULL, 'Sets a custom icon for the checkbox. For more information check https://mantine.dev/core/checkbox', 0, 0, 'Icon'),
(get_style_id('checkbox'), get_field_id('mantine_checkbox_labelPosition'), 'right', 'Sets the position of the label relative to the checkbox. For more information check https://mantine.dev/core/checkbox', 0, 0, 'Label Position'),
(get_style_id('checkbox'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the checkbox component', 0, 1, 'Use Mantine Style'),
(get_style_id('checkbox'), get_field_id('mantine_use_input_wrapper'), 0,'When enabled, uses Input.Wrapper for proper label and description handling. When disabled, renders label and description inline next to the checkbox.', 0, 0, 'Use Input Wrapper');

-- ===========================================
-- DATEPICKER COMPONENT DEFINITION
-- ===========================================

-- Create DatePicker-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_datepicker_type', get_field_type_id('segment'), 0, '{"options":[{"value":"date","text":"Date Only"},{"value":"time","text":"Time Only"},{"value":"datetime","text":"Date & Time"}]}'),
(NULL, 'mantine_datepicker_format', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": false, "placeholder": "YYYY-MM-DD", "options": [{"value": "YYYY-MM-DD", "text": "YYYY-MM-DD"}, {"value": "MM/DD/YYYY", "text": "MM/DD/YYYY"}, {"value": "DD/MM/YYYY", "text": "DD/MM/YYYY"}, {"value": "DD.MM.YYYY", "text": "DD.MM.YYYY"}, {"value": "MMM DD, YYYY", "text": "MMM DD, YYYY"}, {"value": "DD MMM YYYY", "text": "DD MMM YYYY"}]}'),
(NULL, 'mantine_datepicker_locale', get_field_type_id('text'), 0, null),
(NULL, 'mantine_datepicker_placeholder', get_field_type_id('text'), 1, null),
(NULL, 'mantine_datepicker_min_date', get_field_type_id('text'), 0, null),
(NULL, 'mantine_datepicker_max_date', get_field_type_id('text'), 0, null),
(NULL, 'mantine_datepicker_first_day_of_week', get_field_type_id('segment'), 0, '{"options":[{"value":"0","text":"Su"},{"value":"1","text":"Mo"},{"value":"2","text":"Tu"},{"value":"3","text":"We"},{"value":"4","text":"Th"},{"value":"5","text":"Fr"},{"value":"6","text":"Sa"}]}'),
(NULL, 'mantine_datepicker_weekend_days', get_field_type_id('text'), 0, null),
(NULL, 'mantine_datepicker_clearable', get_field_type_id('checkbox'), 0, null),
(NULL, 'mantine_datepicker_allow_deseselect', get_field_type_id('checkbox'), 0, null),
(NULL, 'mantine_datepicker_readonly', get_field_type_id('checkbox'), 0, null),
(NULL, 'mantine_datepicker_with_time_grid', get_field_type_id('checkbox'), 0, null),
(NULL, 'mantine_datepicker_consistent_weeks', get_field_type_id('checkbox'), 0, null),
(NULL, 'mantine_datepicker_hide_outside_dates', get_field_type_id('checkbox'), 0, null),
(NULL, 'mantine_datepicker_hide_weekends', get_field_type_id('checkbox'), 0, null),
(NULL, 'mantine_datepicker_time_step', get_field_type_id('segment'), 0, '{"options":[{"value":"1","text":"1 min"},{"value":"5","text":"5 min"},{"value":"10","text":"10 min"},{"value":"15","text":"15 min"},{"value":"30","text":"30 min"},{"value":"60","text":"1 hour"}]}'),
(NULL, 'mantine_datepicker_time_format', get_field_type_id('segment'), 0, '{"options":[{"value":"12","text":"12-hour"},{"value":"24","text":"24-hour"}]}'),
(NULL, 'mantine_datepicker_date_format', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": false, "placeholder": "YYYY-MM-DD", "options": [{"value": "YYYY-MM-DD", "text": "YYYY-MM-DD"}, {"value": "MM/DD/YYYY", "text": "MM/DD/YYYY"}, {"value": "DD/MM/YYYY", "text": "DD/MM/YYYY"}, {"value": "DD.MM.YYYY", "text": "DD.MM.YYYY"}, {"value": "MMM DD, YYYY", "text": "MMM DD, YYYY"}, {"value": "DD MMM YYYY", "text": "DD MMM YYYY"}]}'),
(NULL, 'mantine_datepicker_time_grid_config', get_field_type_id('textarea'), 0, null),
(NULL, 'mantine_datepicker_with_seconds', get_field_type_id('checkbox'), 0, null);

-- Add datepicker style
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'datepicker',
    
    get_style_group_id('mantine'),
    'Mantine DatePicker component for date, time, and datetime input with comprehensive formatting options',
    0
);

-- Link fields to datepicker style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('datepicker'), get_field_id('label'), NULL, 'Sets the label text displayed above the date picker. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Label'),
(get_style_id('datepicker'), get_field_id('name'), NULL, 'Sets the name attribute for form submission. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Name'),
(get_style_id('datepicker'), get_field_id('value'), NULL, 'Sets the initial date/time value. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Value'),
(get_style_id('datepicker'), get_field_id('is_required'), '0', 'If set, the date picker will be required for form submission. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Required'),
(get_style_id('datepicker'), get_field_id('disabled'), '0', 'If set, the date picker will be disabled. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Disabled'),
(get_style_id('datepicker'), get_field_id('description'), NULL, 'Sets the description text displayed below the label. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Description'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_type'), 'date', 'Sets the type of date picker (date only, time only, or date & time). For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Picker Type'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_placeholder'), NULL, 'Sets the placeholder text for the input field. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Placeholder'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_format'), NULL, 'Sets the custom format string for date/time display. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Format'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_locale'), 'en', 'Sets the locale for date formatting and calendar display. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Locale'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_min_date'), NULL, 'Sets the minimum selectable date. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Min Date'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_max_date'), NULL, 'Sets the maximum selectable date. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Max Date'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_first_day_of_week'), '1', 'Sets the first day of the week (0=Sunday, 1=Monday, etc.). For more information check https://mantine.dev/dates/getting-started', 0, 0, 'First Day of Week'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_weekend_days'), '[0,6]', 'Sets which days are considered weekends as a JSON array. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Weekend Days'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_clearable'), '0', 'If set, allows clearing the selected date/time. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Clearable'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_allow_deseselect'), '0', 'If set, allows deselecting the current date/time. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Allow Deselect'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_readonly'), '0', 'If set, the date picker will be readonly. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Readonly'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_with_time_grid'), '0', 'If set, shows a time grid for time selection. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'With Time Grid'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_consistent_weeks'), '0', 'If set, every month will have 6 weeks to avoid layout shifts. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Consistent Weeks'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_hide_outside_dates'), '0', 'If set, hides dates from other months. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Hide Outside Dates'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_hide_weekends'), '0', 'If set, hides weekend days from the calendar. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Hide Weekends'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_time_step'), '15', 'Sets the time step in minutes for time selection. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Time Step'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_time_format'), '24', 'Sets the time format (12-hour or 24-hour). For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Time Format'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_date_format'), 'YYYY-MM-DD', 'Sets the date format pattern for form submission. Choose from presets or enter a custom format. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'Date Format'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_time_grid_config'), NULL, 'JSON configuration for TimeGrid layout (e.g., {"cols": {"base": 2, "sm": 3}, "spacing": "xs"}). For more information check https://mantine.dev/dates/time-grid', 0, 0, 'Time Grid Config'),
(get_style_id('datepicker'), get_field_id('mantine_datepicker_with_seconds'), '0', 'If set, includes seconds in time selection. For more information check https://mantine.dev/dates/getting-started', 0, 0, 'With Seconds');

-- Create text-input specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_text_input_variant', get_field_type_id('segment'), 0, '{
"options": [
{"value": "default", "text": "Default"},
{"value": "filled", "text": "Filled"},
{"value": "unstyled", "text": "Unstyled"}
]
}');

-- Add text-input style
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'text-input',
    
    get_style_group_id('mantine'),
    'Mantine TextInput component for controlled text input with validation and sections',
    0
);

-- Link fields to text-input style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('text-input'), get_field_id('label'), NULL, 'Sets the label text displayed above the input field. For more information check https://mantine.dev/core/text-input', 0, 0, 'Label'),
(get_style_id('text-input'), get_field_id('name'), NULL, 'Sets the name attribute for form submission. For more information check https://mantine.dev/core/text-input', 0, 0, 'Name'),
(get_style_id('text-input'), get_field_id('value'), NULL, 'Sets the initial value of the input field. For more information check https://mantine.dev/core/text-input', 0, 0, 'Value'),
(get_style_id('text-input'), get_field_id('placeholder'), NULL, 'Sets the placeholder text for the input field. For more information check https://mantine.dev/core/text-input', 0, 0, 'Placeholder'),
(get_style_id('text-input'), get_field_id('description'), NULL, 'Sets the description text displayed below the label. For more information check https://mantine.dev/core/text-input', 0, 0, 'Description'),
(get_style_id('text-input'), get_field_id('is_required'), '0', 'If set, the input field will be required for form submission. For more information check https://mantine.dev/core/text-input', 0, 0, 'Required'),
(get_style_id('text-input'), get_field_id('disabled'), '0', 'If set, the input field will be disabled. For more information check https://mantine.dev/core/text-input', 0, 0, 'Disabled'),
(get_style_id('text-input'), get_field_id('mantine_left_icon'), NULL, 'Sets the content for the left section (typically an icon). For more information check https://mantine.dev/core/text-input', 0, 0, 'Left Section'),
(get_style_id('text-input'), get_field_id('mantine_right_icon'), NULL, 'Sets the content for the right section (typically an icon). For more information check https://mantine.dev/core/text-input', 0, 0, 'Right Section'),
(get_style_id('text-input'), get_field_id('mantine_text_input_variant'), 'default', 'Sets the variant of the input field. For more information check https://mantine.dev/core/text-input', 0, 0, 'Variant'),
(get_style_id('text-input'), get_field_id('mantine_size'), 'sm', 'Sets the size of the input field. For more information check https://mantine.dev/core/text-input', 0, 0, 'Size'),
(get_style_id('text-input'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the input field. For more information check https://mantine.dev/core/text-input', 0, 0, 'Radius'),
(get_style_id('text-input'), get_field_id('translatable'), '0', 'If enabled, this input field will support multi-language translations. When enabled, users can enter values for different languages using tabs.', 0, 0, 'Translatable'),
(get_style_id('text-input'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the text-input component', 0, 1, 'Use Mantine Style');

-- Add spacing fields for text-input (IStyleWithMargin)

-- Create textarea-specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_textarea_autosize', get_field_type_id('checkbox'), 0, null);
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_textarea_min_rows', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "placeholder": "3", "options":[{"value":"1","text":"1"},{"value":"2","text":"2"},{"value":"3","text":"3"},{"value":"4","text":"4"},{"value":"5","text":"5"}]}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_textarea_max_rows', get_field_type_id('select'), 0, '{"creatable": true, "searchable": false, "clearable": true, "placeholder": "8", "options":[{"value":"5","text":"5"},{"value":"8","text":"8"},{"value":"10","text":"10"},{"value":"15","text":"15"},{"value":"20","text":"20"}]}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'mantine_textarea_resize', get_field_type_id('segment'), 0, '{"options":[{"value":"none","text":"None"},{"value":"vertical","text":"Vertical"},{"value":"both","text":"Both"}]}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_textarea_variant', get_field_type_id('segment'), 0, '{
"options": [
{"value": "default", "text": "Default"},
{"value": "filled", "text": "Filled"},
{"value": "unstyled", "text": "Unstyled"}
]
}');

-- Add textarea style
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'textarea',
    
    get_style_group_id('input'),
    'Textarea component for multi-line text input with autosize and resize options. It supports Mantine styling.',
    0
);

-- Link fields to textarea style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('textarea'), get_field_id('label'), NULL, 'Sets the label text displayed above the textarea field. For more information check https://mantine.dev/core/textarea', 0, 0, 'Label'),
(get_style_id('textarea'), get_field_id('name'), NULL, 'Sets the name attribute for form submission. For more information check https://mantine.dev/core/textarea', 0, 0, 'Name'),
(get_style_id('textarea'), get_field_id('value'), NULL, 'Sets the initial value of the textarea field. For more information check https://mantine.dev/core/textarea', 0, 0, 'Value'),
(get_style_id('textarea'), get_field_id('placeholder'), NULL, 'Sets the placeholder text for the textarea field. For more information check https://mantine.dev/core/textarea', 0, 0, 'Placeholder'),
(get_style_id('textarea'), get_field_id('description'), NULL, 'Sets the description text displayed below the label. For more information check https://mantine.dev/core/textarea', 0, 0, 'Description'),
(get_style_id('textarea'), get_field_id('is_required'), '0', 'If set, the textarea field will be required for form submission. For more information check https://mantine.dev/core/textarea', 0, 0, 'Required'),
(get_style_id('textarea'), get_field_id('disabled'), '0', 'If set, the textarea field will be disabled. For more information check https://mantine.dev/core/textarea', 0, 0, 'Disabled'),
(get_style_id('textarea'), get_field_id('mantine_left_icon'), NULL, 'Sets the content for the left section (typically an icon). For more information check https://mantine.dev/core/textarea', 0, 0, 'Left Section'),
(get_style_id('textarea'), get_field_id('mantine_right_icon'), NULL, 'Sets the content for the right section (typically an icon). For more information check https://mantine.dev/core/textarea', 0, 0, 'Right Section'),
(get_style_id('textarea'), get_field_id('mantine_textarea_autosize'), '1', 'If set, the textarea will automatically adjust its height based on content. For more information check https://mantine.dev/core/textarea', 0, 0, 'Autosize'),
(get_style_id('textarea'), get_field_id('mantine_textarea_min_rows'), '3', 'Sets the minimum number of rows when autosize is enabled. For more information check https://mantine.dev/core/textarea', 0, 0, 'Min Rows'),
(get_style_id('textarea'), get_field_id('mantine_textarea_max_rows'), '8', 'Sets the maximum number of rows when autosize is enabled. For more information check https://mantine.dev/core/textarea', 0, 0, 'Max Rows'),
(get_style_id('textarea'), get_field_id('mantine_textarea_resize'), 'none', 'Sets the resize behavior of the textarea. For more information check https://mantine.dev/core/textarea', 0, 0, 'Resize'),
(get_style_id('textarea'), get_field_id('mantine_size'), 'sm', 'Sets the size of the textarea field. For more information check https://mantine.dev/core/textarea', 0, 0, 'Size'),
(get_style_id('textarea'), get_field_id('mantine_radius'), 'sm', 'Sets the border radius of the textarea field. For more information check https://mantine.dev/core/textarea', 0, 0, 'Radius'),
(get_style_id('textarea'), get_field_id('mantine_textarea_variant'), 'default', 'Sets the variant of the textarea field. For more information check https://mantine.dev/core/textarea', 0, 0, 'Variant'),
(get_style_id('textarea'), get_field_id('translatable'), '0', 'If enabled, this textarea field will support multi-language translations. When enabled, users can enter values for different languages using tabs.', 0, 0, 'Translatable'),
(get_style_id('textarea'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the textarea component', 0, 0, 'Use Mantine Style');

-- ===========================================
-- INPUT STYLE DEFINITIONS
-- ===========================================

-- Add input style (HTML input tag, not Mantine)
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'input',
    
    get_style_group_id('input'),
    'HTML input component for various input types (text, email, password, etc.). Renders as standard HTML input tag.',
    0
);

-- Link fields to input style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('input'), get_field_id('type_input'), 'text', 'Sets the input type (text, email, password, number, checkbox, color, date, time, tel, url)', 0, 0, 'Input Type'),
(get_style_id('input'), get_field_id('name'), NULL, 'Sets the name attribute for form submission', 0, 0, 'Name'),
(get_style_id('input'), get_field_id('value'), NULL, 'Sets the initial value of the input field', 0, 0, 'Value'),
(get_style_id('input'), get_field_id('placeholder'), NULL, 'Sets the placeholder text for the input field', 0, 0, 'Placeholder'),
(get_style_id('input'), get_field_id('is_required'), '0', 'If set, the input field will be required for form submission', 0, 0, 'Required'),
(get_style_id('input'), get_field_id('disabled'), '0', 'If set, the input field will be disabled', 0, 0, 'Disabled'),
(get_style_id('input'), get_field_id('min'), NULL, 'Sets the minimum value (for number inputs) or minimum length (for text inputs)', 0, 0, 'Min Value'),
(get_style_id('input'), get_field_id('max'), NULL, 'Sets the maximum value (for number inputs) or maximum length (for text inputs)', 0, 0, 'Max Value'),
(get_style_id('input'), get_field_id('translatable'), '0', 'If enabled, this input field will support multi-language translations. When enabled, users can enter values for different languages using tabs.', 0, 0, 'Translatable');

-- ===========================================
-- SELECT STYLE DEFINITIONS
-- ===========================================

-- Add select style (HTML select tag, not Mantine)
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'select',
    
    get_style_group_id('input'),
    'HTML select component for dropdown selections. Supports single and multiple selections.',
    0
);

-- Link fields to select style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('select'), get_field_id('name'), NULL, 'Sets the name attribute for form submission', 0, 0, 'Name'),
(get_style_id('select'), get_field_id('value'), NULL, 'Sets the initial selected value of the select field', 0, 0, 'Value'),
(get_style_id('select'), get_field_id('placeholder'), 'Select an option', 'Sets the placeholder text for the select field', 0, 0, 'Placeholder'),
(get_style_id('select'), get_field_id('is_required'), '0', 'If set, the select field will be required for form submission', 0, 0, 'Required'),
(get_style_id('select'), get_field_id('disabled'), '0', 'If set, the select field will be disabled', 0, 0, 'Disabled'),
(get_style_id('select'), get_field_id('options'), '[{"value":"option1","label":"Option 1"}, {"value":"option2","label":"Option 2"}]', 'Sets the options for the select field as JSON array. Format: [{"value":"option1","label":"Option 1"}]', 0, 0, 'Options'),
(get_style_id('select'), get_field_id('is_multiple'), '0', 'If set, allows multiple selections', 0, 0, 'Multiple Selection'),
(get_style_id('select'), get_field_id('max'), NULL, 'Sets the maximum number of selections allowed when multiple is enabled', 0, 0, 'Max Selections');

-- ===========================================
-- RICH TEXT EDITOR STYLE DEFINITIONS
-- ===========================================

-- Add rich text editor specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_rich_text_editor_variant', get_field_type_id('segment'), 0, '{"options":[{"value":"default","text":"Default"},{"value":"subtle","text":"Subtle"}]}');

-- Add placeholder field for rich text editor
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_rich_text_editor_placeholder', get_field_type_id('text'), 1, null);

-- Add bubble menu toggle
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_rich_text_editor_bubble_menu', get_field_type_id('checkbox'), 0, null);

-- Add text color toggle
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_rich_text_editor_text_color', get_field_type_id('checkbox'), 0, null);

-- Add task list toggle
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_rich_text_editor_task_list', get_field_type_id('checkbox'), 0, null);

-- Add rich text editor style
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'rich-text-editor',
    
    get_style_group_id('mantine'),
    'Rich text editor component based on Tiptap with toolbar controls for formatting. It supports controlled input for form submission.',
    0
);

-- Link basic fields to rich text editor style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('rich-text-editor'), get_field_id('label'), NULL, 'Sets the label text displayed above the rich text editor. For more information check https://mantine.dev/x/tiptap', 0, 0, 'Label'),
(get_style_id('rich-text-editor'), get_field_id('name'), NULL, 'Sets the name attribute for form submission. For more information check https://mantine.dev/x/tiptap', 0, 0, 'Name'),
(get_style_id('rich-text-editor'), get_field_id('value'), NULL, 'Sets the initial HTML content of the rich text editor. For more information check https://mantine.dev/x/tiptap', 0, 0, 'Value'),
(get_style_id('rich-text-editor'), get_field_id('description'), NULL, 'Sets the description text displayed below the label. For more information check https://mantine.dev/x/tiptap', 0, 0, 'Description'),
(get_style_id('rich-text-editor'), get_field_id('is_required'), '0', 'If set, the rich text editor will be required for form submission.', 0, 0, 'Required'),
(get_style_id('rich-text-editor'), get_field_id('disabled'), '0', 'If set, the rich text editor will be disabled.', 0, 0, 'Disabled'),
(get_style_id('rich-text-editor'), get_field_id('mantine_rich_text_editor_variant'), 'default', 'Sets the variant of the rich text editor.', 0, 0, 'Variant'),

-- New advanced fields
(get_style_id('rich-text-editor'), get_field_id('mantine_rich_text_editor_placeholder'), 'Start writing...', 'Sets the placeholder text shown when the editor is empty. For more information check https://tiptap.dev/docs/editor/extensions/functionality/placeholder', 0, 0, 'Placeholder Text'),
(get_style_id('rich-text-editor'), get_field_id('mantine_rich_text_editor_bubble_menu'), '0', 'If set, enables a bubble menu that appears when text is selected for quick formatting. For more information check https://tiptap.dev/docs/editor/extensions/functionality/bubble-menu', 0, 0, 'Enable Bubble Menu'),
(get_style_id('rich-text-editor'), get_field_id('mantine_rich_text_editor_text_color'), '0', 'If set, enables text color controls in the toolbar. For more information check https://tiptap.dev/docs/editor/extensions/functionality/color', 0, 0, 'Enable Text Color'),
(get_style_id('rich-text-editor'), get_field_id('mantine_rich_text_editor_task_list'), '0', 'If set, enables task list functionality with checkboxes. For more information check https://tiptap.dev/docs/editor/extensions/functionality/task-list', 0, 0, 'Enable Task Lists'),
(get_style_id('rich-text-editor'), get_field_id('translatable'), '0', 'If enabled, this rich text editor field will support multi-language translations. When enabled, users can enter values for different languages using tabs.', 0, 0, 'Translatable'),

-- Mantine style toggle
(get_style_id('rich-text-editor'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the rich text editor component', 0, 1, 'Use Mantine Style');

-- ===========================================
-- FORM STYLES DEFINITIONS
-- ===========================================

-- Form button size field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_buttons_size', get_field_type_id('slider'), 0, '{
"options": [
{"value": "xs", "text": "Extra Small"},
{"value": "sm", "text": "Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value": "xl", "text": "Extra Large"}
]
}');

-- Form button radius field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_buttons_radius', get_field_type_id('slider'), 0, '{
"options": [
{"value": "none", "text": "None"},
{"value": "xs", "text": "Extra Small"},
{"value": "sm", "text": "Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value": "xl", "text": "Extra Large"}
]
}');

-- Form button variant field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_buttons_variant', get_field_type_id('select'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "filled", "options":[
{"value":"filled","text":"Filled"},
{"value":"light","text":"Light"},
{"value":"outline","text":"Outline"},
{"value":"transparent","text":"Transparent"},
{"value":"white","text":"White"},
{"value":"subtle","text":"Subtle"},
{"value":"gradient","text":"Gradient"}
]}');

-- Form buttons position field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_buttons_position', get_field_type_id('select'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "space-between", "options":[
{"value":"space-between","text":"Justified (based on button order)"},
{"value":"center","text":"Centered"},
{"value":"flex-end","text":"Right aligned"},
{"value":"flex-start","text":"Left aligned"}
]}');

-- Form buttons order field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_buttons_order', get_field_type_id('segment'), 0, '{"options":[
{"value":"save-cancel","text":"Save → Cancel"},
{"value":"cancel-save","text":"Cancel → Save"}
]}');

-- Form save button label field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'btn_save_label', get_field_type_id('text'), 1, null);

-- Form update button label field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'btn_update_label', get_field_type_id('text'), 1, null);

-- Form cancel button label field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'btn_cancel_label', get_field_type_id('text'), 1, null);

-- Form save button color field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_btn_save_color', get_field_type_id('color-picker'), 0,  '{
  "options": [
    { "value": "gray", "text": "Gray" },
    { "value": "red", "text": "Red" },
    { "value": "grape", "text": "Grape" },
    { "value": "violet", "text": "Violet" },
    { "value": "blue", "text": "Blue" },
    { "value": "cyan", "text": "Cyan" },
    { "value": "green", "text": "Green" },
    { "value": "lime", "text": "Lime" },
    { "value": "yellow", "text": "Yellow" },
    { "value": "orange", "text": "Orange" }
  ]
}');

-- Form cancel button color field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_btn_cancel_color', get_field_type_id('color-picker'), 0,  '{
  "options": [
    { "value": "gray", "text": "Gray" },
    { "value": "red", "text": "Red" },
    { "value": "grape", "text": "Grape" },
    { "value": "violet", "text": "Violet" },
    { "value": "blue", "text": "Blue" },
    { "value": "cyan", "text": "Cyan" },
    { "value": "green", "text": "Green" },
    { "value": "lime", "text": "Lime" },
    { "value": "yellow", "text": "Yellow" },
    { "value": "orange", "text": "Orange" }
  ]
}');

-- Form update button color field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'mantine_btn_update_color', get_field_type_id('color-picker'), 0,  '{
  "options": [
    { "value": "gray", "text": "Gray" },
    { "value": "red", "text": "Red" },
    { "value": "grape", "text": "Grape" },
    { "value": "violet", "text": "Violet" },
    { "value": "blue", "text": "Blue" },
    { "value": "cyan", "text": "Cyan" },
    { "value": "green", "text": "Green" },
    { "value": "lime", "text": "Lime" },
    { "value": "yellow", "text": "Yellow" },
    { "value": "orange", "text": "Orange" }
  ]
}');

-- Form redirect URL field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'redirect_at_end', get_field_type_id('select-page-keyword'), 0, null);

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'btn_cancel_url', get_field_type_id('select-page-keyword'), 0, null);

-- Form alert error field
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
(NULL, 'alert_error', get_field_type_id('textarea'), 1, null);

-- Add form-log style
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'form-log',
    
    get_style_group_id('form'),
    'Log form component that clears data after successful submission. Supports multiple entries and form validation.',
    1
);

-- Add form-record style
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'form-record',
    
    get_style_group_id('form'),
    'Record form component that preserves data and updates existing records. Pre-populates fields with existing data.',
    1
);

-- Link fields to form-log style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
-- Basic form fields
(get_style_id('form-log'), get_field_id('name'), NULL, 'Sets the form name for identification and API calls', 0, 0, 'Form Name'),
(get_style_id('form-log'), get_field_id('is_log'), '1', 'Enables log mode - form clears after successful submission', 0, 1, 'Log Mode'),
(get_style_id('form-log'), get_field_id('redirect_at_end'), NULL, 'URL to redirect to after successful form submission', 0, 0, 'Redirect URL'),

-- Alert fields
(get_style_id('form-log'), get_field_id('alert_success'), '', 'Success message displayed after form submission', 0, 0, 'Success Message'),
(get_style_id('form-log'), get_field_id('alert_error'), 'An error occurred while submitting the form', 'Error message displayed when form submission fails', 0, 0, 'Error Message'),

-- Button configuration (with update button)
(get_style_id('form-log'), get_field_id('btn_save_label'), 'Save', 'Text displayed on the save button for new records', 0, 0, 'Save Button Label'),
(get_style_id('form-log'), get_field_id('btn_cancel_label'), 'Cancel', 'Text displayed on the cancel button', 0, 0, 'Cancel Button Label'),
(get_style_id('form-log'), get_field_id('btn_cancel_url'), NULL, 'URL to navigate to when cancel button is clicked', 0, 0, 'Cancel URL'),

-- Button styling
(get_style_id('form-log'), get_field_id('mantine_buttons_size'), 'sm', 'Size of the form buttons', 0, 0, 'Button Size'),
(get_style_id('form-log'), get_field_id('mantine_buttons_radius'), 'sm', 'Border radius of the form buttons', 0, 0, 'Button Radius'),
(get_style_id('form-log'), get_field_id('mantine_buttons_variant'), 'filled', 'Visual style variant for the buttons', 0, 0, 'Button Variant'),
(get_style_id('form-log'), get_field_id('mantine_buttons_position'), 'space-between', 'Positioning of the buttons container', 0, 0, 'Button Position'),
(get_style_id('form-log'), get_field_id('mantine_buttons_order'), 'save-cancel', 'Order of buttons (which button appears first)', 0, 0, 'Button Order'),
(get_style_id('form-log'), get_field_id('mantine_btn_save_color'), 'blue', 'Color theme for the save button', 0, 0, 'Save Button Color'),
(get_style_id('form-log'), get_field_id('mantine_btn_cancel_color'), 'gray', 'Color theme for the cancel button', 0, 0, 'Cancel Button Color'),

-- Mantine style toggle
(get_style_id('form-log'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the form component', 0, 1, 'Use Mantine Style');

-- Link fields to form-record style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
-- Basic form fields (inherited from form-log)
(get_style_id('form-record'), get_field_id('name'), NULL, 'Sets the form name for identification and API calls', 0, 0, 'Form Name'),
(get_style_id('form-record'), get_field_id('is_log'), '0', 'Disables log mode - form preserves data for record updates', 0, 1, 'Log Mode'),
(get_style_id('form-record'), get_field_id('redirect_at_end'), NULL, 'URL to redirect to after successful form submission', 0, 0, 'Redirect URL'),

-- Alert fields
(get_style_id('form-record'), get_field_id('alert_success'), '', 'Success message displayed after form submission', 0, 0, 'Success Message'),
(get_style_id('form-record'), get_field_id('alert_error'), 'An error occurred while saving the record', 'Error message displayed when form submission fails', 0, 0, 'Error Message'),

-- Button configuration (with update button)
(get_style_id('form-record'), get_field_id('btn_save_label'), 'Save', 'Text displayed on the save button for new records', 0, 0, 'Save Button Label'),
(get_style_id('form-record'), get_field_id('btn_update_label'), 'Update', 'Text displayed on the update button for existing records', 0, 0, 'Update Button Label'),
(get_style_id('form-record'), get_field_id('btn_cancel_label'), 'Cancel', 'Text displayed on the cancel button', 0, 0, 'Cancel Button Label'),
(get_style_id('form-record'), get_field_id('btn_cancel_url'), NULL, 'URL to navigate to when cancel button is clicked', 0, 0, 'Cancel URL'),

-- Button styling (with update button color)
(get_style_id('form-record'), get_field_id('mantine_buttons_size'), 'sm', 'Size of the form buttons', 0, 0, 'Button Size'),
(get_style_id('form-record'), get_field_id('mantine_buttons_radius'), 'sm', 'Border radius of the form buttons', 0, 0, 'Button Radius'),
(get_style_id('form-record'), get_field_id('mantine_buttons_variant'), 'filled', 'Visual style variant for the buttons', 0, 0, 'Button Variant'),
(get_style_id('form-record'), get_field_id('mantine_buttons_position'), 'space-between', 'Positioning of the buttons container', 0, 0, 'Button Position'),
(get_style_id('form-record'), get_field_id('mantine_buttons_order'), 'save-cancel', 'Order of buttons (which button appears first)', 0, 0, 'Button Order'),
(get_style_id('form-record'), get_field_id('mantine_btn_save_color'), 'blue', 'Color theme for the save button', 0, 0, 'Save Button Color'),
(get_style_id('form-record'), get_field_id('mantine_btn_update_color'), 'orange', 'Color theme for the update button', 0, 0, 'Update Button Color'),
(get_style_id('form-record'), get_field_id('mantine_btn_cancel_color'), 'gray', 'Color theme for the cancel button', 0, 0, 'Cancel Button Color'),

-- Mantine style toggle
(get_style_id('form-record'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the form component', 0, 0, 'Use Mantine Style');

-- ===========================================
-- HTML TAG STYLE
-- ===========================================

-- Add html-tag style (can have children for flexible custom UI)
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'html-tag',
    
    get_style_group_id('wrapper'),
    'Raw HTML tag component for custom flexible UI designs - allows rendering any HTML element with children',
    1
);

-- Add content field for html-tag (translatable textarea)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (
    NULL,
    'html_tag_content',
    get_field_type_id('textarea'),
    1,
    '{"placeholder": "Enter HTML content or text", "description": "Content to display inside the HTML tag"}'
);

-- Add tag field for html-tag (dropdown with all HTML tags)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (
    NULL,
    'html_tag',
    get_field_type_id('select'),
    0,
    '{"searchable": true, "clearable": false, "creatable": false, "placeholder": "div", "options": [
        {"value": "div", "text": "<div> - Division"},
        {"value": "span", "text": "<span> - Inline Container"},
        {"value": "p", "text": "<p> - Paragraph"},
        {"value": "h1", "text": "<h1> - Heading 1"},
        {"value": "h2", "text": "<h2> - Heading 2"},
        {"value": "h3", "text": "<h3> - Heading 3"},
        {"value": "h4", "text": "<h4> - Heading 4"},
        {"value": "h5", "text": "<h5> - Heading 5"},
        {"value": "h6", "text": "<h6> - Heading 6"},
        {"value": "section", "text": "<section> - Section"},
        {"value": "article", "text": "<article> - Article"},
        {"value": "aside", "text": "<aside> - Aside"},
        {"value": "header", "text": "<header> - Header"},
        {"value": "footer", "text": "<footer> - Footer"},
        {"value": "nav", "text": "<nav> - Navigation"},
        {"value": "main", "text": "<main> - Main Content"},
        {"value": "ul", "text": "<ul> - Unordered List"},
        {"value": "ol", "text": "<ol> - Ordered List"},
        {"value": "li", "text": "<li> - List Item"},
        {"value": "dl", "text": "<dl> - Description List"},
        {"value": "dt", "text": "<dt> - Description Term"},
        {"value": "dd", "text": "<dd> - Description Definition"},
        {"value": "blockquote", "text": "<blockquote> - Blockquote"},
        {"value": "pre", "text": "<pre> - Preformatted Text"},
        {"value": "code", "text": "<code> - Code"},
        {"value": "em", "text": "<em> - Emphasis"},
        {"value": "strong", "text": "<strong> - Strong Emphasis"},
        {"value": "b", "text": "<b> - Bold"},
        {"value": "i", "text": "<i> - Italic"},
        {"value": "u", "text": "<u> - Underline"},
        {"value": "mark", "text": "<mark> - Highlight"},
        {"value": "small", "text": "<small> - Small Text"},
        {"value": "sup", "text": "<sup> - Superscript"},
        {"value": "sub", "text": "<sub> - Subscript"},
        {"value": "cite", "text": "<cite> - Citation"},
        {"value": "q", "text": "<q> - Quote"},
        {"value": "abbr", "text": "<abbr> - Abbreviation"},
        {"value": "dfn", "text": "<dfn> - Definition"},
        {"value": "time", "text": "<time> - Time"},
        {"value": "var", "text": "<var> - Variable"},
        {"value": "samp", "text": "<samp> - Sample Output"},
        {"value": "kbd", "text": "<kbd> - Keyboard Input"},
        {"value": "address", "text": "<address> - Address"},
        {"value": "del", "text": "<del> - Deleted Text"},
        {"value": "ins", "text": "<ins> - Inserted Text"},
        {"value": "s", "text": "<s> - Strikethrough"},
        {"value": "figure", "text": "<figure> - Figure"},
        {"value": "figcaption", "text": "<figcaption> - Figure Caption"},
        {"value": "table", "text": "<table> - Table"},
        {"value": "thead", "text": "<thead> - Table Head"},
        {"value": "tbody", "text": "<tbody> - Table Body"},
        {"value": "tfoot", "text": "<tfoot> - Table Foot"},
        {"value": "tr", "text": "<tr> - Table Row"},
        {"value": "th", "text": "<th> - Table Header"},
        {"value": "td", "text": "<td> - Table Cell"},
        {"value": "caption", "text": "<caption> - Table Caption"},
        {"value": "colgroup", "text": "<colgroup> - Table Column Group"},
        {"value": "col", "text": "<col> - Table Column"},
        {"value": "fieldset", "text": "<fieldset> - Fieldset"},
        {"value": "legend", "text": "<legend> - Legend"},
        {"value": "label", "text": "<label> - Label"},
        {"value": "button", "text": "<button> - Button"},
        {"value": "output", "text": "<output> - Output"},
        {"value": "meter", "text": "<meter> - Meter"},
        {"value": "details", "text": "<details> - Details"},
        {"value": "summary", "text": "<summary> - Summary"},
        {"value": "dialog", "text": "<dialog> - Dialog"},
        {"value": "canvas", "text": "<canvas> - Canvas"},
        {"value": "svg", "text": "<svg> - SVG"},
        {"value": "picture", "text": "<picture> - Picture"},
        {"value": "img", "text": "<img> - Image"},
        {"value": "a", "text": "<a> - Link"}
    ]}'
);

-- Associate fields with html-tag style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
(get_style_id('html-tag'), get_field_id('html_tag_content'), NULL, 'Translatable content to display inside the HTML tag. This field supports multiple languages.', 0, 0, 'Content'),
(get_style_id('html-tag'), get_field_id('html_tag'), 'div', 'Select the HTML tag to render. This provides raw HTML flexibility for custom UI designs.', 0, 0, 'HTML Tag');

UPDATE `fields`
SET id_type = get_field_type_id('textarea')
WHERE name = 'confirmation_message';

-- ===========================================
-- PROFILE STYLE - USER PROFILE MANAGEMENT
-- ===========================================

-- Add new style 'profile' for user profile management
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'profile',
    
    get_style_group_id('Admin'),
    'User profile management component with account settings, password reset, and account deletion',
    0
);

-- Profile Section Title
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_title', get_field_type_id('text'), 1, '{"placeholder": "My Profile", "description": "Main title for the profile section"}');

-- User Information Display Labels
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_label_email', get_field_type_id('text'), 1, '{"placeholder": "Email", "description": "Label for email field display"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_label_username', get_field_type_id('text'), 1, '{"placeholder": "Username", "description": "Label for username field display"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_label_name', get_field_type_id('text'), 1, '{"placeholder": "Full Name", "description": "Label for full name field display"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_label_created', get_field_type_id('text'), 1, '{"placeholder": "Account Created", "description": "Label for account creation date"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_label_last_login', get_field_type_id('text'), 1, '{"placeholder": "Last Login", "description": "Label for last login date"}');

-- Account Information Section Title
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_account_info_title', get_field_type_id('text'), 1, '{"placeholder": "Account Information", "description": "Title for the account information section"}');

-- Name Change Section
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_name_change_title', get_field_type_id('text'), 1, '{"placeholder": "Change Display Name", "description": "Title for name change section"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_name_change_description', get_field_type_id('textarea'), 1, '{"placeholder": "<p>Update your display name. This will be visible to other users.</p>", "description": "Description explaining name change functionality"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_name_change_label', get_field_type_id('text'), 1, '{"placeholder": "New Display Name", "description": "Label for new name input field"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_name_change_placeholder', get_field_type_id('text'), 1, '{"placeholder": "Enter new display name", "description": "Placeholder text for name input"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_name_change_button', get_field_type_id('text'), 1, '{"placeholder": "Update Display Name", "description": "Text for the name change button"}');

-- Name Change Messages
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_name_change_success', get_field_type_id('text'), 1, '{"placeholder": "Display name updated successfully!", "description": "Success message for name change"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_name_change_error_required', get_field_type_id('text'), 1, '{"placeholder": "Display name is required", "description": "Error message when name is empty"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_name_change_error_invalid', get_field_type_id('text'), 1, '{"placeholder": "Display name contains invalid characters", "description": "Error message for invalid name format"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_name_change_error_general', get_field_type_id('text'), 1, '{"placeholder": "Failed to update display name. Please try again.", "description": "General error message for name change failures"}');

-- Password Reset Section
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_title', get_field_type_id('text'), 1, '{"placeholder": "Change Password", "description": "Title for password reset section"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_description', get_field_type_id('textarea'), 1, '{"placeholder": "<p>Set a new password for your account. Make sure it is strong and secure.</p>", "description": "Description explaining password reset functionality"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_label_current', get_field_type_id('text'), 1, '{"placeholder": "Current Password", "description": "Label for current password input field"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_label_new', get_field_type_id('text'), 1, '{"placeholder": "New Password", "description": "Label for new password input field"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_label_confirm', get_field_type_id('text'), 1, '{"placeholder": "Confirm New Password", "description": "Label for password confirmation input field"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_placeholder_current', get_field_type_id('text'), 1, '{"placeholder": "Enter current password", "description": "Placeholder for current password input"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_placeholder_new', get_field_type_id('text'), 1, '{"placeholder": "Enter new password", "description": "Placeholder for new password input"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_placeholder_confirm', get_field_type_id('text'), 1, '{"placeholder": "Confirm new password", "description": "Placeholder for password confirmation input"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_button', get_field_type_id('text'), 1, '{"placeholder": "Update Password", "description": "Text for the password reset button"}');

-- Password Reset Messages
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_success', get_field_type_id('text'), 1, '{"placeholder": "Password updated successfully!", "description": "Success message for password change"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_error_current_required', get_field_type_id('text'), 1, '{"placeholder": "Current password is required", "description": "Error message when current password is empty"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_error_current_wrong', get_field_type_id('text'), 1, '{"placeholder": "Current password is incorrect", "description": "Error message when current password is wrong"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_error_new_required', get_field_type_id('text'), 1, '{"placeholder": "New password is required", "description": "Error message when new password is empty"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_error_confirm_required', get_field_type_id('text'), 1, '{"placeholder": "Password confirmation is required", "description": "Error message when confirmation password is empty"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_error_mismatch', get_field_type_id('text'), 1, '{"placeholder": "New passwords do not match", "description": "Error message when passwords don''t match"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_error_weak', get_field_type_id('text'), 1, '{"placeholder": "Password is too weak. Please choose a stronger password.", "description": "Error message for weak password"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_password_reset_error_general', get_field_type_id('text'), 1, '{"placeholder": "Failed to update password. Please try again.", "description": "General error message for password change failures"}');

-- Account Deletion Section
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_delete_title', get_field_type_id('text'), 1, '{"placeholder": "Delete Account", "description": "Title for account deletion section"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_delete_description', get_field_type_id('textarea'), 1, '{"placeholder": "<p>Permanently delete your account and all associated data. This action cannot be undone.</p>", "description": "Warning description for account deletion"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_delete_alert_text', get_field_type_id('text'), 1, '{"placeholder": "This action cannot be undone. All your data will be permanently deleted.", "description": "Warning text in the delete account alert"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_delete_modal_warning', get_field_type_id('textarea'), 1, '{"placeholder": "Deleting your account will permanently remove all your data, including profile information, preferences, and any content you have created.", "description": "Detailed warning text in the delete account modal"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_delete_label_email', get_field_type_id('text'), 1, '{"placeholder": "Confirm Email", "description": "Label for email confirmation field"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_delete_placeholder_email', get_field_type_id('text'), 1, '{"placeholder": "Enter your email to confirm", "description": "Placeholder for email confirmation input"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_delete_button', get_field_type_id('text'), 1, '{"placeholder": "Delete Account", "description": "Text for the account deletion button"}');

-- Account Deletion Messages
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_delete_success', get_field_type_id('text'), 1, '{"placeholder": "Account deleted successfully.", "description": "Success message for account deletion"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_delete_error_email_required', get_field_type_id('text'), 1, '{"placeholder": "Email confirmation is required", "description": "Error message when email is empty"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_delete_error_email_mismatch', get_field_type_id('text'), 1, '{"placeholder": "Email does not match your account email", "description": "Error message when email doesn''t match"}');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_delete_error_general', get_field_type_id('text'), 1, '{"placeholder": "Failed to delete account. Please try again.", "description": "General error message for account deletion failures"}');

-- UI Configuration Fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_gap', get_field_type_id('select'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "md", "options":[
{"value":"xs","text":"Extra Small (8px)"},
{"value":"sm","text":"Small (12px)"},
{"value":"md","text":"Medium (16px)"},
{"value":"lg","text":"Large (20px)"},
{"value":"xl","text":"Extra Large (24px)"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_use_accordion', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_accordion_multiple', get_field_type_id('checkbox'), 0, null);

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_accordion_default_opened', get_field_type_id('select'), 0, '{"multiSelect": true, "searchable": false, "clearable": true, "placeholder": "Select sections to open by default", "options":[
{"value":"user_info","text":"User Information"},
{"value":"username_change","text":"Change Username"},
{"value":"password_reset","text":"Change Password"},
{"value":"account_delete","text":"Delete Account"}
]}');

-- Styling Fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_variant', get_field_type_id('select'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "default", "options":[
{"value":"default","text":"Default"},
{"value":"filled","text":"Filled"},
{"value":"outline","text":"Outline"},
{"value":"light","text":"Light"},
{"value":"subtle","text":"Subtle"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_radius', get_field_type_id('slider'), 0, '{"creatable": true, "searchable": false, "clearable": true, "placeholder": "sm", "options":[
{"value":"xs","text":"Extra Small"},
{"value":"sm","text":"Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value":"xl","text":"Extra Large"}
]}');

INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_shadow', get_field_type_id('slider'), 0, '{"creatable": false, "searchable": false, "clearable": true, "placeholder": "none", "options":[
{"value":"none","text":"No Shadow"},
{"value":"xs","text":"Extra Small"},
{"value":"sm","text":"Small"},
{"value":"md","text":"Medium"},
{"value":"lg","text":"Large"},
{"value":"xl","text":"Extra Large"}
]}');

-- Layout Configuration (for non-accordion mode)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES (NULL, 'profile_columns', get_field_type_id('select'), 0, '{"creatable": false, "searchable": false, "clearable": false, "placeholder": "1", "options":[
{"value":"1","text":"1 Column"},
{"value":"2","text":"2 Columns"},
{"value":"3","text":"3 Columns"},
{"value":"4","text":"4 Columns"}
]}');

-- Associate all fields with profile style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
-- Profile Section
(get_style_id('profile'), get_field_id('profile_title'), 'My Profile', 'Main title displayed at the top of the profile page', 0, 0, 'Profile Title'),

-- User Information Display
(get_style_id('profile'), get_field_id('profile_account_info_title'), 'Account Information', 'Title for the account information section', 0, 0, 'Account Info Title'),
(get_style_id('profile'), get_field_id('profile_label_email'), 'Email', 'Label for displaying user email', 0, 0, 'Email Label'),
(get_style_id('profile'), get_field_id('profile_label_username'), 'Username', 'Label for displaying username', 0, 0, 'Username Label'),
(get_style_id('profile'), get_field_id('profile_label_name'), 'Full Name', 'Label for displaying full name', 0, 0, 'Name Label'),
(get_style_id('profile'), get_field_id('profile_label_created'), 'Account Created', 'Label for account creation date', 0, 0, 'Created Label'),
(get_style_id('profile'), get_field_id('profile_label_last_login'), 'Last Login', 'Label for last login date', 0, 0, 'Last Login Label'),

-- Name Change
(get_style_id('profile'), get_field_id('profile_name_change_title'), 'Change Display Name', 'Section title for name change', 0, 0, 'Name Change Title'),
(get_style_id('profile'), get_field_id('profile_name_change_description'), '<p>Update your display name. This will be visible to other users.</p>', 'Description explaining name change', 0, 0, 'Name Change Description'),
(get_style_id('profile'), get_field_id('profile_name_change_label'), 'New Display Name', 'Label for name input field', 0, 0, 'Name Input Label'),
(get_style_id('profile'), get_field_id('profile_name_change_placeholder'), 'Enter new display name', 'Placeholder for name input', 0, 0, 'Name Placeholder'),
(get_style_id('profile'), get_field_id('profile_name_change_button'), 'Update Display Name', 'Button text for name change', 0, 0, 'Name Change Button'),

-- Name Change Messages
(get_style_id('profile'), get_field_id('profile_name_change_success'), 'Display name updated successfully!', 'Success message after name change', 0, 0, 'Name Success Message'),
(get_style_id('profile'), get_field_id('profile_name_change_error_required'), 'Display name is required', 'Error when name field is empty', 0, 0, 'Name Required Error'),
(get_style_id('profile'), get_field_id('profile_name_change_error_invalid'), 'Display name contains invalid characters', 'Error for invalid name format', 0, 0, 'Name Invalid Error'),
(get_style_id('profile'), get_field_id('profile_name_change_error_general'), 'Failed to update display name. Please try again.', 'General name change error', 0, 0, 'Name General Error'),

-- Password Reset
(get_style_id('profile'), get_field_id('profile_password_reset_title'), 'Change Password', 'Section title for password change', 0, 0, 'Password Reset Title'),
(get_style_id('profile'), get_field_id('profile_password_reset_description'), '<p>Set a new password for your account. Make sure it is strong and secure.</p>', 'Description explaining password change', 0, 0, 'Password Reset Description'),
(get_style_id('profile'), get_field_id('profile_password_reset_label_current'), 'Current Password', 'Label for current password field', 0, 0, 'Current Password Label'),
(get_style_id('profile'), get_field_id('profile_password_reset_label_new'), 'New Password', 'Label for new password field', 0, 0, 'New Password Label'),
(get_style_id('profile'), get_field_id('profile_password_reset_label_confirm'), 'Confirm New Password', 'Label for password confirmation field', 0, 0, 'Confirm Password Label'),
(get_style_id('profile'), get_field_id('profile_password_reset_placeholder_current'), 'Enter current password', 'Placeholder for current password', 0, 0, 'Current Password Placeholder'),
(get_style_id('profile'), get_field_id('profile_password_reset_placeholder_new'), 'Enter new password', 'Placeholder for new password', 0, 0, 'New Password Placeholder'),
(get_style_id('profile'), get_field_id('profile_password_reset_placeholder_confirm'), 'Confirm new password', 'Placeholder for password confirmation', 0, 0, 'Confirm Password Placeholder'),
(get_style_id('profile'), get_field_id('profile_password_reset_button'), 'Update Password', 'Button text for password change', 0, 0, 'Password Change Button'),

-- Password Reset Messages
(get_style_id('profile'), get_field_id('profile_password_reset_success'), 'Password updated successfully!', 'Success message after password change', 0, 0, 'Password Success Message'),
(get_style_id('profile'), get_field_id('profile_password_reset_error_current_required'), 'Current password is required', 'Error when current password is empty', 0, 0, 'Current Password Required Error'),
(get_style_id('profile'), get_field_id('profile_password_reset_error_current_wrong'), 'Current password is incorrect', 'Error when current password is wrong', 0, 0, 'Current Password Wrong Error'),
(get_style_id('profile'), get_field_id('profile_password_reset_error_new_required'), 'New password is required', 'Error when new password is empty', 0, 0, 'New Password Required Error'),
(get_style_id('profile'), get_field_id('profile_password_reset_error_confirm_required'), 'Password confirmation is required', 'Error when confirmation is empty', 0, 0, 'Confirm Password Required Error'),
(get_style_id('profile'), get_field_id('profile_password_reset_error_mismatch'), 'New passwords do not match', 'Error when passwords don''t match', 0, 0, 'Password Mismatch Error'),
(get_style_id('profile'), get_field_id('profile_password_reset_error_weak'), 'Password is too weak. Please choose a stronger password.', 'Error for weak password', 0, 0, 'Weak Password Error'),
(get_style_id('profile'), get_field_id('profile_password_reset_error_general'), 'Failed to update password. Please try again.', 'General password change error', 0, 0, 'Password General Error'),

-- Account Deletion
(get_style_id('profile'), get_field_id('profile_delete_title'), 'Delete Account', 'Section title for account deletion', 0, 0, 'Delete Account Title'),
(get_style_id('profile'), get_field_id('profile_delete_description'), '<p>Permanently delete your account and all associated data. This action cannot be undone.</p>', 'Warning description for account deletion', 0, 0, 'Delete Account Description'),
(get_style_id('profile'), get_field_id('profile_delete_alert_text'), 'This action cannot be undone. All your data will be permanently deleted.', 'Warning text in the delete account alert', 0, 0, 'Delete Alert Text'),
(get_style_id('profile'), get_field_id('profile_delete_modal_warning'), '<p>Deleting your account will permanently remove all your data, including profile information, preferences, and any content you have created.</p>', 'Detailed warning text in the delete account modal', 0, 0, 'Delete Modal Warning'),
(get_style_id('profile'), get_field_id('profile_delete_label_email'), 'Confirm Email', 'Label for email confirmation field', 0, 0, 'Email Confirmation Label'),
(get_style_id('profile'), get_field_id('profile_delete_placeholder_email'), 'Enter your email to confirm', 'Placeholder for email confirmation', 0, 0, 'Email Confirmation Placeholder'),
(get_style_id('profile'), get_field_id('profile_delete_button'), 'Delete Account', 'Button text for account deletion', 0, 0, 'Delete Account Button'),

-- Account Deletion Messages
(get_style_id('profile'), get_field_id('profile_delete_success'), 'Account deleted successfully.', 'Success message after account deletion', 0, 0, 'Account Deletion Success'),
(get_style_id('profile'), get_field_id('profile_delete_error_email_required'), 'Email confirmation is required', 'Error when email field is empty', 0, 0, 'Email Required Error'),
(get_style_id('profile'), get_field_id('profile_delete_error_email_mismatch'), 'Email does not match your account email', 'Error when email doesn''t match', 0, 0, 'Email Mismatch Error'),
(get_style_id('profile'), get_field_id('profile_delete_error_general'), 'Failed to delete account. Please try again.', 'General account deletion error', 0, 0, 'Account Deletion General Error'),

-- UI Configuration
(get_style_id('profile'), get_field_id('profile_gap'), 'md', 'Spacing between profile sections', 0, 0, 'Section Spacing'),
(get_style_id('profile'), get_field_id('profile_use_accordion'), '0', 'Wrap profile sections in accordion for collapsible interface', 0, 0, 'Use Accordion'),
(get_style_id('profile'), get_field_id('profile_accordion_multiple'), '1', 'Allow multiple accordion sections to be open simultaneously', 0, 0, 'Multiple Accordion'),
(get_style_id('profile'), get_field_id('profile_accordion_default_opened'), 'user_info', 'Which accordion sections should be opened by default', 0, 0, 'Default Opened Sections'),

-- Styling Options
(get_style_id('profile'), get_field_id('profile_variant'), 'default', 'Visual style variant for the profile cards', 0, 0, 'Card Variant'),
(get_style_id('profile'), get_field_id('profile_radius'), 'sm', 'Border radius for profile cards', 0, 0, 'Border Radius'),
(get_style_id('profile'), get_field_id('profile_shadow'), 'sm', 'Shadow effect for profile cards', 0, 0, 'Shadow Effect'),

-- Layout Configuration
(get_style_id('profile'), get_field_id('profile_columns'), '2', 'Number of columns for non-accordion layout', 0, 0, 'Layout Columns'),

(get_style_id('profile'), get_field_id('use_mantine_style'), '1', 'Use Mantine style for the profile container', 0, 1, 'Use Mantine Style'),

-- Add spacing fields for profile (IStyleWithMarginAndPadding)
(get_style_id('profile'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Profile component', 0, 0, 'Spacing');


-- ===========================================
-- VALIDATE STYLE DEFINITIONS
-- ===========================================

-- Add validate style (internal form for account validation with token)
INSERT IGNORE INTO `styles` (`id`, `name`, `id_group`, `description`, `can_have_children`) VALUES (
    NULL,
    'validate',
    
    get_style_group_id('Admin'),
    'User account validation form that accepts user ID and token from URL, validates and activates account. Can have children for additional form fields.',
    1
);

-- Validate form specific fields
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`, `config`) VALUES
-- Basic labels
(NULL, 'label_name', get_field_type_id('text'), 1, '{"placeholder": "Name"}'),
(NULL, 'label_pw', get_field_type_id('text'), 1, '{"placeholder": "Password"}'),
(NULL, 'label_pw_confirm', get_field_type_id('text'), 1, '{"placeholder": "Confirm Password"}'),
(NULL, 'title', get_field_type_id('text'), 1, '{"placeholder": "Account Validation"}'),
(NULL, 'subtitle', get_field_type_id('textarea'), 1, '{"rows": 2, "placeholder": "Please complete your account setup"}'),


-- Placeholders and descriptions
(NULL, 'name_placeholder', get_field_type_id('text'), 1, '{"placeholder": "Enter your full name"}'),
(NULL, 'name_description', get_field_type_id('textarea'), 1, '{"rows": 2, "placeholder": "Your display name"}'),
(NULL, 'pw_placeholder', get_field_type_id('text'), 1, '{"placeholder": "Enter your password"}'),
(NULL, 'anonymous_user_name_description', get_field_type_id('textarea'), 1, '{"rows": 2, "placeholder": "This name will be visible to other users"}'),

-- Alert messages
(NULL, 'alert_fail', get_field_type_id('text'), 1, '{"placeholder": "Validation failed"}'),
(NULL, 'alert_success', get_field_type_id('text'), 1, '{"placeholder": "Validation successful"}'),

-- Form configuration fields (non-translatable)
(NULL, 'label_activate', get_field_type_id('text'), 1, '{"placeholder": "Activate Account"}'),
(NULL, 'label_save', get_field_type_id('text'), 1, '{"placeholder": "Save"}'),
(NULL, 'label_update', get_field_type_id('text'), 1, '{"placeholder": "Update"}'),
(NULL, 'label_cancel', get_field_type_id('text'), 1, '{"placeholder": "Cancel"}'),
(NULL, 'name', get_field_type_id('text'), 0, '{"placeholder": "validate_form"}'),
(NULL, 'is_log', get_field_type_id('checkbox'), 0, null),
(NULL, 'redirect_at_end', get_field_type_id('select-page-keyword'), 0, null),
(NULL, 'cancel_url', get_field_type_id('select-page-keyword'), 0, null),
(NULL, 'ajax', get_field_type_id('checkbox'), 0, null);

-- Link fields to validate style
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
-- Basic form configuration
(get_style_id('validate'), get_field_id('name'), 'validate_form', 'Sets the form name for identification and API calls', 0, 0, 'Form Name'),
(get_style_id('validate'), get_field_id('redirect_at_end'), 'login', 'URL to redirect to after successful account validation', 0, 0, 'Redirect URL'),
(get_style_id('validate'), get_field_id('btn_cancel_url'), NULL, 'URL to navigate to when cancel button is clicked', 0, 0, 'Cancel URL'),

-- Title and subtitle
(get_style_id('validate'), get_field_id('title'), 'Account Validation', 'Main heading displayed at the top of the validation form', 0, 0, 'Form Title'),
(get_style_id('validate'), get_field_id('subtitle'), 'Please complete your account setup to activate your account', 'Subtitle text displayed below the title', 0, 0, 'Form Subtitle'),

-- Field labels
(get_style_id('validate'), get_field_id('label_name'), 'Name', 'Label for the name input field', 0, 0, 'Name Field Label'),
(get_style_id('validate'), get_field_id('label_pw'), 'Password', 'Label for the password input field', 0, 0, 'Password Field Label'),
(get_style_id('validate'), get_field_id('label_pw_confirm'), 'Confirm Password', 'Label for the password confirmation field', 0, 0, 'Confirm Password Label'),

-- Placeholders and descriptions
(get_style_id('validate'), get_field_id('name_placeholder'), 'Enter your full name', 'Placeholder text for the name input field', 0, 0, 'Name Placeholder'),
(get_style_id('validate'), get_field_id('name_description'), NULL, 'Help text displayed below the name field', 0, 0, 'Name Description'),
(get_style_id('validate'), get_field_id('pw_placeholder'), 'Enter your password', 'Placeholder text for the password field', 0, 0, 'Password Placeholder'),
(get_style_id('validate'), get_field_id('anonymous_user_name_description'), 'This name will be visible to other users', 'Description text for anonymous user name field', 0, 0, 'Anonymous User Description'),

-- Alert messages
(get_style_id('validate'), get_field_id('alert_success'), 'Account validated successfully! Welcome to our platform.', 'Success message displayed after successful validation', 0, 0, 'Success Message'),
(get_style_id('validate'), get_field_id('alert_fail'), 'Validation failed. Please check your information and try again.', 'Error message displayed when validation fails', 0, 0, 'Error Message'),

-- Button configuration
(get_style_id('validate'), get_field_id('label_activate'), 'Activate Account', 'Text for the account activation button', 0, 0, 'Activate Button Label'),
(get_style_id('validate'), get_field_id('label_save'), 'Save', 'Text for the save button (fallback)', 0, 0, 'Save Button Label'),
(get_style_id('validate'), get_field_id('label_update'), 'Update', 'Text for the update button (fallback)', 0, 0, 'Update Button Label'),
(get_style_id('validate'), get_field_id('label_cancel'), 'Cancel', 'Text for the cancel button', 0, 0, 'Cancel Button Label'),

-- Button styling (with activate button color)
(get_style_id('validate'), get_field_id('mantine_buttons_size'), 'sm', 'Size of the form buttons', 0, 0, 'Button Size'),
(get_style_id('validate'), get_field_id('mantine_buttons_radius'), 'sm', 'Border radius of the form buttons', 0, 0, 'Button Radius'),
(get_style_id('validate'), get_field_id('mantine_buttons_variant'), 'filled', 'Visual style variant for the buttons', 0, 0, 'Button Variant'),
(get_style_id('validate'), get_field_id('mantine_buttons_position'), 'space-between', 'Positioning of the buttons container', 0, 0, 'Button Position'),
(get_style_id('validate'), get_field_id('mantine_buttons_order'), 'save-cancel', 'Order of buttons (activate appears first)', 0, 0, 'Button Order'),
(get_style_id('validate'), get_field_id('mantine_btn_save_color'), 'blue', 'Color theme for the activate button', 0, 0, 'Activate Button Color'),
(get_style_id('validate'), get_field_id('mantine_btn_cancel_color'), 'gray', 'Color theme for the cancel button', 0, 0, 'Cancel Button Color'),

-- Card styling
(get_style_id('validate'), get_field_id('mantine_card_shadow'), 'sm', 'Shadow effect for the validation card', 0, 0, 'Card Shadow'),
(get_style_id('validate'), get_field_id('mantine_card_padding'), 'lg', 'Padding inside the validation card', 0, 0, 'Card Padding'),
(get_style_id('validate'), get_field_id('mantine_border'), '1', 'Show border around the validation card', 0, 0, 'Card Border'),
(get_style_id('validate'), get_field_id('mantine_radius'), 'sm', 'Border radius for the validation card', 0, 0, 'Card Radius'),

-- Add spacing fields for validate (IStyleWithMarginAndPadding)
(get_style_id('validate'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Validate component', 0, 0, 'Spacing'),

-- Mantine style toggle
(get_style_id('validate'), get_field_id('use_mantine_style'), '1', 'Use Mantine styling for the validation form component', 0, 1, 'Use Mantine Style');

-- ===========================================
-- ADD SPACING FIELDS TO ALL STYLES
-- ===========================================

-- Styles with only margin fields (IStyleWithMargin)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
-- login
(get_style_id('login'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Login component', 0, 0, 'Margin'),

-- textarea
(get_style_id('textarea'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Textarea component', 0, 0, 'Margin'),

-- rich-text-editor
(get_style_id('rich-text-editor'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the RichTextEditor component', 0, 0, 'Margin'),

-- radio
(get_style_id('radio'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Radio component', 0, 0, 'Margin'),

-- slider
(get_style_id('slider'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Slider component', 0, 0, 'Margin'),

-- checkbox
(get_style_id('checkbox'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Checkbox component', 0, 0, 'Margin'),

-- datepicker
(get_style_id('datepicker'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the DatePicker component', 0, 0, 'Margin'),

-- image
(get_style_id('image'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Image component', 0, 0, 'Margin'),

-- link
(get_style_id('link'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Link component', 0, 0, 'Margin'),

-- tabs
(get_style_id('tabs'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Tabs component', 0, 0, 'Margin'),

-- color-input
(get_style_id('color-input'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the ColorInput component', 0, 0, 'Margin'),

-- color-picker
(get_style_id('color-picker'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the ColorPicker component', 0, 0, 'Margin'),

-- file-input
(get_style_id('file-input'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the FileInput component', 0, 0, 'Margin'),

-- number-input
(get_style_id('number-input'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the NumberInput component', 0, 0, 'Margin'),

-- range-slider
(get_style_id('range-slider'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the RangeSlider component', 0, 0, 'Margin'),

-- segmented-control
(get_style_id('segmented-control'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the SegmentedControl component', 0, 0, 'Margin'),

-- switch
(get_style_id('switch'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Switch component', 0, 0, 'Margin'),

-- combobox
(get_style_id('combobox'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Combobox component', 0, 0, 'Margin'),

-- action-icon
(get_style_id('action-icon'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the ActionIcon component', 0, 0, 'Margin'),

-- badge
(get_style_id('badge'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Badge component', 0, 0, 'Margin'),

-- chip
(get_style_id('chip'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Chip component', 0, 0, 'Margin'),

-- avatar
(get_style_id('avatar'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Avatar component', 0, 0, 'Margin'),

-- timeline
(get_style_id('timeline'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Timeline component', 0, 0, 'Margin'),

-- kbd
(get_style_id('kbd'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Kbd component', 0, 0, 'Margin'),

-- rating
(get_style_id('rating'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Rating component', 0, 0, 'Margin'),

-- progress
(get_style_id('progress'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Progress component', 0, 0, 'Margin'),

-- theme-icon
(get_style_id('theme-icon'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the ThemeIcon component', 0, 0, 'Margin'),

-- title
(get_style_id('title'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Title component', 0, 0, 'Margin'),

-- tab
(get_style_id('tab'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Tab component', 0, 0, 'Margin'),

-- indicator
(get_style_id('indicator'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Indicator component', 0, 0, 'Margin'),

-- divider
(get_style_id('divider'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Divider component', 0, 0, 'Margin'),

-- progress-section
(get_style_id('progress-section'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the ProgressSection component', 0, 0, 'Margin'),

-- progress-root
(get_style_id('progress-root'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the ProgressRoot component', 0, 0, 'Margin'),

-- aspect-ratio
(get_style_id('aspect-ratio'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the AspectRatio component', 0, 0, 'Margin'),

-- text-input
(get_style_id('text-input'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the TextInput component', 0, 0, 'Margin'),

-- figure
(get_style_id('figure'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Figure component', 0, 0, 'Margin'),

-- video
(get_style_id('video'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Video component', 0, 0, 'Margin'),

-- audio
(get_style_id('audio'), get_field_id('mantine_spacing_margin'), '', 'Sets the margin of the Audio component', 0, 0, 'Margin');

-- Styles with margin and padding fields (IStyleWithMarginAndPadding)
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`, `disabled`, `hidden`, `title`) VALUES
-- register
(get_style_id('register'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Register component', 0, 0, 'Spacing'),

-- resetPassword
(get_style_id('resetPassword'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the ResetPassword component', 0, 0, 'Spacing'),

-- twoFactorAuth
(get_style_id('twoFactorAuth'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the TwoFactorAuth component', 0, 0, 'Spacing'),

-- container
(get_style_id('container'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Container component', 0, 0, 'Spacing'),

-- paper
(get_style_id('paper'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Paper component', 0, 0, 'Spacing'),

-- alert
(get_style_id('alert'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Alert component', 0, 0, 'Spacing'),

-- form-log
(get_style_id('form-log'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the FormLog component', 0, 0, 'Spacing'),

-- form-record
(get_style_id('form-record'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the FormRecord component', 0, 0, 'Spacing'),

-- carousel
(get_style_id('carousel'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Carousel component', 0, 0, 'Spacing'),

-- flex
(get_style_id('flex'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Flex component', 0, 0, 'Spacing'),

-- group
(get_style_id('group'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Group component', 0, 0, 'Spacing'),

-- simple-grid
(get_style_id('simple-grid'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the SimpleGrid component', 0, 0, 'Spacing'),

-- scroll-area
(get_style_id('scroll-area'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the ScrollArea component', 0, 0, 'Spacing'),

-- grid
(get_style_id('grid'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Grid component', 0, 0, 'Spacing'),

-- grid-column
(get_style_id('grid-column'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the GridColumn component', 0, 0, 'Spacing'),

-- space
(get_style_id('space'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Space component', 0, 0, 'Spacing'),

-- stack
(get_style_id('stack'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Stack component', 0, 0, 'Spacing'),

-- accordion
(get_style_id('accordion'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Accordion component', 0, 0, 'Spacing'),

-- accordion-item
(get_style_id('accordion-item'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the AccordionItem component', 0, 0, 'Spacing'),

-- notification
(get_style_id('notification'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Notification component', 0, 0, 'Spacing'),

-- text
(get_style_id('text'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Text component', 0, 0, 'Spacing'),

-- code
(get_style_id('code'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Code component', 0, 0, 'Spacing'),

-- highlight
(get_style_id('highlight'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Highlight component', 0, 0, 'Spacing'),

-- blockquote
(get_style_id('blockquote'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Blockquote component', 0, 0, 'Spacing'),

-- card
(get_style_id('card'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Card component', 0, 0, 'Spacing'),

-- card-segment
(get_style_id('card-segment'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the CardSegment component', 0, 0, 'Spacing'),

-- list
(get_style_id('list'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the List component', 0, 0, 'Spacing'),

-- list-item
(get_style_id('list-item'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the ListItem component', 0, 0, 'Spacing'),

-- background-image
(get_style_id('background-image'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the BackgroundImage component', 0, 0, 'Spacing'),

-- fieldset
(get_style_id('fieldset'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Fieldset component', 0, 0, 'Spacing'),

-- spoiler
(get_style_id('spoiler'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Spoiler component', 0, 0, 'Spacing'),

-- box
(get_style_id('box'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Box component', 0, 0, 'Spacing'),

-- typography
(get_style_id('typography'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Typography component', 0, 0, 'Spacing'),

-- center
(get_style_id('center'), get_field_id('mantine_spacing_margin_padding'), '', 'Sets the margin and padding of the Center component', 0, 0, 'Spacing');