<?php

namespace WpabCb\Core;

use DateTime;
use Exception;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * A powerful, reusable, Laravel-inspired validation class for CampaignBay.
 *
 * @since      1.0.0
 * @package    WPAB_CampaignBay
 * @author     WP Anchor Bay <wpanchorbay@gmail.com>
 */
class Validator
{

	private $data;
	private $errors = array();
	private $validated_data = array();

	/**
	 * Constructor.
	 *
	 * @param array $data The raw data array to be validated.
	 */
	public function __construct($data)
	{
		$this->data = $data;
	}

	/**
	 * Main validation method. Runs data against a set of rules.
	 *
	 * @param array $rules An array defining the validation rules for each field.
	 * @return bool True if validation passes, false otherwise.
	 */
	public function validate($rules)
	{
		$this->errors = array();
		$this->validated_data = array();

		foreach ($rules as $field => $rule_string) {
			$rules_for_field = explode('|', $rule_string);
			$value = $this->data[$field] ?? null;

			foreach ($rules_for_field as $rule) {
				if ($rule === 'datetime' && !is_null($value)) {
					$value = self::validate_datetime($value);
					$this->data[$field] = $value;
				}
				// campaignbay_log('field : ' . $field);
				$this->apply_rule($field, $value, $rule);
			}

			// If no errors for this field, add it to the validated data.
			if (!isset($this->errors[$field])) {
				$this->validated_data[$field] = $value;
			}
		}

		return empty($this->errors);
	}

	/**
	 * Applies a single validation rule to a field.
	 *
	 * @param string $field The name of the field.
	 * @param mixed  $value The value of the field.
	 * @param string $rule  The rule string (e.g., 'required', 'max:255').
	 */
	private function apply_rule($field, $value, $rule)
	{
		// Parse rule name and parameters (e.g., 'max:255' -> 'max', [255]).
		$params = explode(':', $rule);
		$rule_name = array_shift($params);
		$param_str = implode(':', $params); // Re-join params in case the value contains a colon (like a date format).

		switch ($rule_name) {
			case 'datetime':
				break;
			case 'required':
				// $value === null || ( is_string( $value ) && trim( $value ) === '' ) || ( is_array( $value ) && empty( $value ) ) 
				if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && empty($value))) {
					$this->add_error($field, __('This field is required.' . $value, 'campaignbay'));
				}
				break;

			case 'required_if':
				$rule_params = explode(',', $param_str);
				$other_field = array_shift($rule_params);
				$required_values = $rule_params;
				$other_value = $this->data[$other_field] ?? null;
				campaignbay_log("Checking required_if for field: {$field}, other_field: {$other_field}, other_value: {$other_value}, required_values: " . implode(', ', $required_values) . ", current_value: " . (is_array($value) ? 'Array' : (string) $value));


				if ($other_field && in_array($other_value, $required_values, false)) {
					campaignbay_log('=======1');
					if (is_array($value)) {
						campaignbay_log('=======2');
						if (empty($value)) {
							campaignbay_log('=======3');
							$this->add_error($field, __('This field cannot be empty.', 'campaignbay'));
						}
					} elseif ((is_null($value) || trim((string) $value) === '' || $value == 0)) {
						campaignbay_log('=======4');
						$this->add_error($field, __('This field is required.', 'campaignbay'));
					}
				}

				// if ( $other_field && (is_array($required_values) && in_array( $other_value, $required_values, true )) && ( is_null( $value ) || trim( (string) $value ) === '' || $value == 0 ) ) {
				// 	$this->add_error( $field, sprintf( __( 'This field is required when %s is one of %s.', 'campaignbay' ), $other_field, implode( ', ', $required_values ) ) );
				// }

				// if ( $other_field && $other_value == $required_values && ( is_null( $value ) || trim( (string) $value ) === '' || $value == 0 ) ) {
				// 	$this->add_error( $field, sprintf( __( 'This field is required when %s is %s.', 'campaignbay' ), $other_field, implode( ', ', $required_values ) ) );
				// }

				// campaignbay_log( "other_field: $other_field, other_value: $other_value, required_values: " . implode( ', ', (array) $required_values ) . ", value: $value" );
				break;

			case 'in':
				$allowed = explode(',', $param_str);
				if (!is_null($value) && !in_array((string) $value, $allowed, true)) {
					$this->add_error($field, __('The selected value is invalid.', 'campaignbay'));
				}
				break;

			case 'numeric':
				if (!is_null($value) && !is_numeric($value)) {
					$this->add_error($field, __('This field must be a number.', 'campaignbay'));
				}
				break;

			case 'integer':
				if ($value !== null && filter_var($value, FILTER_VALIDATE_INT) === false) {
					$this->add_error($field, __('This field must be an integer.', 'campaignbay'));
				}
				break;

			case 'array':
				if (!is_null($value) && !is_array($value)) {
					$this->add_error($field, __('This field must be an array.', 'campaignbay'));
				}
				break;

			case 'array_of_integers':
				if (!is_null($value)) {
					if (!is_array($value)) {
						$this->add_error($field, __('This field must be an array.', 'campaignbay'));
					} else {
						foreach ($value as $item) {
							if (!filter_var($item, FILTER_VALIDATE_INT)) {
								$this->add_error($field, __('All items in this field must be integers.', 'campaignbay'));
								break; // Stop checking after the first failure.
							}
						}
					}
				}
				break;

			case 'max':
				$max_val = (int) $param_str;
				if (is_string($value) && mb_strlen($value) > $max_val) {
					$this->add_error($field, sprintf(__('This field may not be greater than %d characters.', 'campaignbay'), $max_val));
				} elseif (is_numeric($value) && (float) $value > $max_val) {
					$this->add_error($field, sprintf(__('This field may not be greater than %d.', 'campaignbay'), $max_val));
				}
				break;

			case 'min':
				$min_val = (int) $param_str;
				if (is_string($value) && mb_strlen($value) < $min_val) {
					$this->add_error($field, sprintf(__('This field must be at least %d characters.', 'campaignbay'), $min_val));
				} elseif (is_numeric($value) && (float) $value < $min_val) {
					$this->add_error($field, sprintf(__('This field must be at least %d.', 'campaignbay'), $min_val));
				}
				break;

			case 'min_if':
				$rule_params = explode(',', $param_str);
				$other_field = array_shift($rule_params);
				$required_values = $rule_params;
				$other_value = $this->data[$other_field] ?? null;

				if ($other_field && in_array($other_value, $required_values, true)) {
					$min_val = (int) array_pop($rule_params);
					if (is_string($value)) {
						if (mb_strlen($value) < $min_val) {
							$this->add_error($field, sprintf(__('This field must be at least %d characters.', 'campaignbay'), $min_val));
						}
					} elseif (is_numeric($value)) {
						if ((float) $value < $min_val) {
							$this->add_error($field, sprintf(__('This field must be at least %d', 'campaignbay'), $min_val));
						}
					}
				}
				break;

			case 'max_if':
				$rule_params = explode(',', $param_str);
				$other_field = array_shift($rule_params);
				$required_values = $rule_params;
				$other_value = $this->data[$other_field] ?? null;
				if ($other_field && (in_array($other_value, $required_values, true) || $other_value === $required_values)) {
					$max_val = (int) array_pop($rule_params);
					if (is_numeric($value)) {
						if ((float) $value > $max_val) {
							$this->add_error($field, sprintf(__('This field must be at least %d', 'campaignbay'), $max_val));
						}
					} elseif (is_string($value)) {
						if (mb_strlen($value) > $max_val) {
							$this->add_error($field, sprintf(__('This field may not be greater than %d characters.', 'campaignbay'), $max_val));
						}
					}
				}
				break;

			case 'gte':
				$other_field = $param_str;
				$other_value = $this->data[$other_field] ?? null;
				if (is_numeric($value) && is_numeric($other_value)) {
					if ((float) $value < (float) $other_value) {
						$this->add_error($field, sprintf(__('This field must be greater than or equal to the %s field.', 'campaignbay'), $other_field));
					}
				}
				break;
			case 'lte':
				$other_field = $param_str;
				$other_value = $this->data[$other_field] ?? null;
				if (is_numeric($value) && is_numeric($other_value)) {
					if ((float) $value > (float) $other_value) {
						$this->add_error($field, sprintf(__('This field must be less than or equal to the %s field.', 'campaignbay'), $other_field));
					}
				}
				break;
		}
	}

	/**
	 * Adds an error message for a specific field.
	 *
	 * @param string $field The field that failed validation.
	 * @param string $message The error message.
	 */
	private function add_error($field, $message)
	{
		$this->errors[$field] = array("message" => $message);
	}

	public function fails()
	{
		return !empty($this->errors);
	}

	public function get_first_error()
	{
		return !empty($this->errors) ? reset($this->errors) : '';
	}

	public function get_errors()
	{
		return $this->errors;
	}

	public function get_validated_data()
	{
		return $this->validated_data;
	}

	private static function validate_datetime($datetime)
	{
		if (empty($datetime)) {
			return null;
		}
		try {
			$date = new DateTime($datetime);
			return $date->format('Y-m-d H:i:s');
		} catch (Exception $e) {
			return null;
		}
	}
}