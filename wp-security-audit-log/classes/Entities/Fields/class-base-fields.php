<?php
/**
 * Extension: Base fields.
 *
 * @since 5.1.0
 *
 * @package   wsal
 * @subpackage entities
 */

declare(strict_types=1);

namespace WSAL\Entities;

use WSAL\MainWP\MainWP_Addon;
use WSAL\MainWP\MainWP_Helper;
use WSAL\Entities\Metadata_Entity;
use WSAL\Entities\Occurrences_Entity;
use WSAL\Helpers\DateTime_Formatter_Helper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WSAL\Entities\Base_Fields' ) ) {
	/**
	 * Provides fields searching functionality.
	 *
	 * @since 5.0.0
	 */
	class Base_Fields {
		/**
		 * Filed must have:
		 * - unique name of field (no spaces)
		 * - DB relation (which DB field represents the field)
		 * - type
		 * - validation
		 */

		/**
		 * Mapper method - maps all the fields for searching to the corresponding fields (and how to extract value if they are not directly callable from the occurrence table)
		 *
		 * @return array
		 *
		 * @since 5.0.0
		 */
		public static function prepare_all_fields(): array {

			// Collects the default table fields.
			$fields = Occurrences_Entity::get_fields();

			$table_meta = Metadata_Entity::get_table_name();

			/**
			 * Logic for searching in the meta table for some values.
			 *
			 * @param sql_prefix - The SQL prefix to search for given values
			 * @param sub_sql_string - The SQL part of the query used to search for specific values (how)
			 * @param sql_suffix - The SQL suffix for the search query
			 * @param column_name - Which column from the occurrences table to map the results to
			 */
			$fields_subs = array(
				'post_title' => array(
					'sql_prefix'     => 'SELECT occurrence_id FROM ' . $table_meta . ' as meta WHERE meta.name=\'PostTitle\' AND ( ',
					'sub_sql_string' => "( (meta.value LIKE '%s') > 0 )",
					'sql_suffix'     => ' )',
					'column_name'    => 'occurrence_id',
				),
			);

			/**
			 * All the user fields logic for collecting
			 * That differs because the users can be searched using mails, names, roles etc...
			 *
			 * With this we are mapping the logic for extraction the user IDs using different user searc criteria
			 *
			 * @param call - Which method (and class) to call with the parameters.
			 * @param field_name - The name of the field to extract data from.
			 * @param in_table - Marks what to use for searching in the occurrences table (they are currently the same)
			 */
			$user_fields = array(
				'user_first_name' => array(
					'call'       => array( self::class, 'users_search' ),
					'field_name' => 'first_name',
					'in_table'   => array(
						'user_id'  => 'ID',
						'username' => 'user_login',
					),
				),
				'user_last_name'  => array(
					'call'       => array( self::class, 'users_search' ),
					'field_name' => 'last_name',
					'in_table'   => array(
						'user_id'  => 'ID',
						'username' => 'user_login',
					),
				),
				'user_email'      => array(
					'call'       => array( self::class, 'users_search' ),
					'field_name' => 'user_email',
					'in_table'   => array(
						'user_id'  => 'ID',
						'username' => 'user_login',
					),
				),
				'user_role'       => array(
					'call'     => array( self::class, 'users_search' ),
					'extract'  => 'role',
					'in_table' => array(
						'user_id'  => 'ID',
						'username' => 'user_login',
					),
				),
				'user_id'         => array(
					'call'       => array( self::class, 'users_search' ),
					'field_name' => 'ID',
					'in_table'   => array(
						'user_id'  => 'ID',
						'username' => 'user_login',
					),
				),
			);

			$fields_aliases = array(
				'post_title' => array( 'post_name' ),
			);

			foreach ( array_keys( $fields_subs ) as $key ) {
				if ( isset( $fields_aliases[ $key ] ) ) {
					foreach ( $fields_aliases[ $key ] as $alias ) {
						$fields_subs[ $alias ] = $fields_subs[ $key ];
					}
				}
			}

			// Dates.
			$dates = array(
				'start_date' => 'date',
				'end_date'   => 'date',
			);

			return \array_merge( $fields_subs, $fields, $dates, $user_fields );
		}

		/**
		 * Parses the given search string (human readable) and returns the WHERE SQL statement based on the given string logic. To construct this use "field_name" followed by ":" and then the value (no spaces are supported) if there is a space in the string use '"'. For exclusions use "-" before the field name.
		 *
		 * @param string $search_string - Example: alert_id:1000 post_name:"something" user_id:1 .
		 *
		 * @return string
		 *
		 * @since 5.0.0
		 */
		public static function string_to_search( string $search_string ): string {
			/*
			 * alert_id:1000 post_name:"something" user_id:1
			 */

			// There is nothing to parse - bounce.
			if ( ! \strpos( $search_string, ':' ) ) {
				return $search_string;
			}

			$fields_values = array();

			$where_sql = '';

			$occurrences_ids_to_search  = array();
			$occurrences_ids_to_exclude = array();

			// Remove trailing and leading spaces.
			$string = trim( $search_string );

			$raw_fields = \explode( ' ', $string );

			foreach ( $raw_fields as $field ) {
				if ( empty( $field ) || ! \strpos( $field, ':' ) ) {
					continue;
				}
				list($field_key, $field_value) = \explode( ':', trim( $field ) );

				$exc_flag = false;

				if ( false !== \strpos( $field_key, '-' ) ) {
					$exc_flag  = true;
					$field_key = ltrim( $field_key, $field_key[0] );
				}

				if ( '' !== trim( $field_value ) ) {
					if ( isset( $fields_values[ $field_key ] ) ) {
						if ( $exc_flag ) {
							$fields_values[ $field_key ]['exc'][] = $field_value;
						} else {
							$fields_values[ $field_key ][] = $field_value;
						}
					} else {
						$fields_values[ $field_key ] = array();
						if ( $exc_flag ) {
							$fields_values[ $field_key ]['exc'][] = $field_value;
						} else {
							$fields_values[ $field_key ][] = $field_value;
						}
					}
				} else {
					continue;
				}
			}

			$get_sub_fields = self::prepare_all_fields();

			foreach ( $fields_values as $filed_name => $filed_values ) {
				if ( isset( $get_sub_fields[ $filed_name ] ) && isset( $get_sub_fields[ $filed_name ]['sql_prefix'] ) ) {
					self::meta_search( $get_sub_fields[ $filed_name ], $filed_values, $occurrences_ids_to_search, $occurrences_ids_to_exclude );
				}

				if ( isset( $get_sub_fields[ $filed_name ] ) && isset( $get_sub_fields[ $filed_name ]['call'] ) ) {
					$where_sql .= \call_user_func( $get_sub_fields[ $filed_name ]['call'], $get_sub_fields[ $filed_name ], $filed_values ) . ' AND ';

				}

				if ( isset( $get_sub_fields[ $filed_name ] ) && ( ! isset( $get_sub_fields[ $filed_name ]['call'] ) && ! isset( $get_sub_fields[ $filed_name ]['sql_prefix'] ) ) ) {
					$where_sql .= self::direct_field_call( (string) $filed_name, $filed_values ) . ' AND ';
				}
			}

			$where_sql = \rtrim( $where_sql, ' AND ' );

			return $where_sql;
		}

		/**
		 * Special class method for searching for users based on different criteria - user first name, user roles, etc...
		 *
		 * @param array $search_keys - Keys (from field mapping logic @see prepare_all_fields method) giving the logic of how to search for user based on specific field.
		 * @param array $search_values - Values collected from the search string to search for.
		 *
		 * @return string
		 *
		 * @since 5.0.0
		 */
		public static function users_search( array $search_keys, array $search_values ) {
			$exclude = false;

			if ( isset( $search_values['exc'] ) ) {
				$exclude            = true;
				$search_user_values = $search_values['exc'];
			} else {
				$search_user_values = $search_values;
			}

			if ( isset( $search_keys['field_name'] ) ) {
				$users = array();

				$exclude = false;

				if ( isset( $search_values['exc'] ) ) {
					$exclude            = true;
					$search_user_values = $search_values['exc'];
				} else {
					$search_user_values = $search_values;
				}

				$args = array(
					'blog_id'    => 0,
					'meta_query' => array(
						array(
							'key'     => $search_keys['field_name'],
							'values'  => $search_user_values,
							'compare' => 'REGEXP',
						),
					),
				);

				if ( 'ID' === $search_keys['field_name'] ) {
					unset( $args['meta_query'] );
					$args['include'] = $search_user_values;
				}

				if ( 'user_email' === $search_keys['field_name'] ) {
					global $wpdb;
					$search_str  = '';
					$search_vals = array();
					foreach ( $search_user_values as $value ) {
						$search_str   .= ' ' . $search_keys['field_name'] . ' LIKE %s OR ';
						$search_vals[] = $value;
					}
					$search_str  = \rtrim( $search_str, ' OR ' );
					$users_array = $wpdb->get_results(
						$wpdb->prepare( 'SELECT * FROM ' . $wpdb->users . ' WHERE ' . $search_str, $search_vals ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					); // This will return list of users with matching email domain.
				} else {
					$users_array = \get_users( $args );
				}

				if ( MainWP_Addon::check_mainwp_plugin_active() ) {

					$mainwp_users = MainWP_Helper::find_users_by( array( $search_keys['field_name'] ), $search_user_values, false );

					$users_array = array_merge( $users_array, $mainwp_users );
				}

				foreach ( $users_array as $user ) {
					if ( isset( $search_keys['in_table'] ) ) {
						$arr = array();
						foreach ( $search_keys['in_table'] as $field_name => $object_name ) {
							$arr[ $field_name ] = "'" . $user->$object_name . "'";
						}

						$users[] = $arr;
					} else {
						$users[] = array( 'user_id' => $user->ID );
					}
				}

				$from_string_array = array();

				if ( ! empty( $users ) ) {
					foreach ( $users as $user ) {
						foreach ( $user as $field_name => $field_value ) {
							if ( ! empty( $field_value ) ) {
								$from_string_array[ $field_name ][] = $field_value;
							}
						}
					}
				}
				$users_sql = '';
				foreach ( $from_string_array as $field_name => $field_values ) {
					$users_sql .= ( ( true === $exclude ) ? ' ( ' . $field_name . ' IS NULL OR ' : '' ) . $field_name . ( ( true === $exclude ) ? ' NOT' : '' ) . ' IN ( ' . \implode( ',', $field_values ) . ( ( true === $exclude ) ? ' ) ) AND ' : ' ) OR ' );
				}

				if ( true === $exclude ) {
					$users_sql = ' ( ' . \rtrim( $users_sql, ' AND ' ) . ' ) ';
				} else {
					$users_sql = ' ( ' . \rtrim( $users_sql, ' OR ' ) . ' ) ';
				}

				if ( true === $exclude ) {
					unset( $search_values['exc'] );
					if ( ! empty( $search_values ) ) {
						$users_sql .= ' AND ' . self::users_search( $search_keys, $search_values );
					}
				}

				return $users_sql;
			} elseif ( isset( $search_keys['extract'] ) ) {
				if ( 'role' === $search_keys['extract'] ) {

					$users_sql = '';
					foreach ( $search_user_values as $field_value ) {
						$users_sql .= ( ( true === $exclude ) ? '!' : '' ) . " FIND_IN_SET('" . $field_value . "',`user_roles`) " . ( ( true === $exclude ) ? ' AND ' : ' OR ' );
					}

					if ( true === $exclude ) {
						$users_sql = ' ( ' . \rtrim( $users_sql, ' AND ' ) . ' ) ';
					} else {
						$users_sql = ' ( ' . \rtrim( $users_sql, ' OR ' ) . ' ) ';
					}

					if ( true === $exclude ) {
						unset( $search_values['exc'] );
						if ( ! empty( $search_values ) ) {
							$users_sql .= ' AND ' . self::users_search( $search_keys, $search_values );
						}
					}

					return $users_sql;
				}
			}
		}

		/**
		 * Special class method used for searching in the meta table for the given filed/value based on the mapping provided in the @see prepare_all_fields
		 *
		 * @param array $search_keys - Keys (from field mapping logic @see prepare_all_fields method) giving the logic of how to search for user based on specific field.
		 * @param array $search_values - Values collected from the search string to search for.
		 * @param array $occurrences_ids_to_search - Array with the collected IDs to search for so far.
		 * @param array $occurrences_ids_to_exclude - Array with the collected IDs to exclude from search so far.
		 *
		 * @return void
		 *
		 * @since 5.0.0
		 */
		public static function meta_search( array $search_keys, array $search_values, array &$occurrences_ids_to_search, array &$occurrences_ids_to_exclude ) {
			$sub_sql = $search_keys['sql_prefix'];

			$exclude = false;

			if ( isset( $search_values['exc'] ) ) {
				$exclude            = true;
				$search_user_values = $search_values['exc'];
			} else {
				$search_user_values = $search_values;
			}

			foreach ( $search_user_values as $value ) {
				$sub_sql .= $search_keys['sub_sql_string'] . ' OR ';
			}

			$sub_sql = \rtrim( $sub_sql, ' OR ' );

			$sub_sql .= $search_keys['sql_suffix'];

			$_wpdb = Metadata_Entity::get_connection();

			$sql     = $_wpdb->prepare( $sub_sql, $search_user_values );
			$results = $_wpdb->get_results( $sql, ARRAY_A );

			if ( ! empty( $results ) ) {
				if ( true === $exclude ) {
					$occurrences_ids_to_exclude = \array_merge( $occurrences_ids_to_exclude, array_column( $results, $search_keys['column_name'] ) );
				} else {
					$occurrences_ids_to_search = \array_merge( $occurrences_ids_to_search, array_column( $results, $search_keys['column_name'] ) );
				}
			}

			if ( true === $exclude ) {
				unset( $search_values['exc'] );
				if ( ! empty( $search_values ) ) {
					self::meta_search( $search_keys, $search_values, $occurrences_ids_to_search, $occurrences_ids_to_exclude );
				}
			}
		}

		/**
		 * Falls here if the filed / value pair to search for is directly present in the occurrences table - meaning that there is no need to map, or extract additional data.
		 *
		 * @param string $field_name - The name of the field to search for.
		 * @param array  $search_values - The search values provided.
		 *
		 * @return string
		 *
		 * @since 5.0.0
		 */
		public static function direct_field_call( string $field_name, array $search_values ) {
			$exclude = false;

			$field_sql = '';

			if ( \in_array( $field_name, array( 'start_date', 'end_date' ), true ) ) {
				if ( 'start_date' === $field_name ) {
					$start_datetime   = \DateTime::createFromFormat( 'Y-m-d H:i:s', $search_values[0] . ' 00:00:00' );
					$_start_timestamp = $start_datetime->format( 'U' ) + ( DateTime_Formatter_Helper::get_time_zone_offset() ) * -1;
					$field_sql       .= " created_on >= {$_start_timestamp} ";
				} else {
					$end_datetime   = \DateTime::createFromFormat( 'Y-m-d H:i:s', $search_values[0] . ' 23:59:59' );
					$_end_timestamp = $end_datetime->format( 'U' ) + ( DateTime_Formatter_Helper::get_time_zone_offset() ) * -1;
					$field_sql     .= " created_on <= {$_end_timestamp} ";
				}
			} else {

				if ( isset( $search_values['exc'] ) ) {
					$exclude             = true;
					$search_field_values = $search_values['exc'];
				} else {
					$search_field_values = $search_values;
				}
				foreach ( $search_field_values as $field_values ) {
					$field_sql .= ( ( true === $exclude ) ? ' ( ' . $field_name . ' IS NULL OR ' : '' ) . $field_name . ( ( true === $exclude ) ? ' NOT' : '' ) . ' IN ( ' . "'" . \implode( ",'", (array) $field_values ) . "'" . ( ( true === $exclude ) ? ' ) ) AND ' : ' ) OR ' );
				}

				if ( true === $exclude ) {
					$field_sql = ' ( ' . \rtrim( $field_sql, ' AND ' ) . ' ) ';
				} else {
					$field_sql = ' ( ' . \rtrim( $field_sql, ' OR ' ) . ' ) ';
				}

				if ( true === $exclude ) {
					unset( $search_values['exc'] );
					if ( ! empty( $search_values ) ) {
						$field_sql .= ' AND ' . self::direct_field_call( $field_name, $search_values );
					}
				}
			}

			return $field_sql;
		}

		/*
		public static function convert_sql_where_to_php( string $where_clause ) {

			// Trim and clean the input.
			$where_clause = trim( $where_clause );

			// Remove leading "WHERE" if present.
			if ( stripos( $where_clause, 'WHERE' ) === 0 ) {
				$where_clause = substr( $where_clause, 5 );
			}

			// Remove whitespace.
			$where_clause = preg_replace( '/\s+/', ' ', $where_clause );

			// Process the conditions.
			return self::parse_conditions( $where_clause );
		}

		public static function parse_conditions( $clause ) {
			// Handle nested conditions using parentheses.

			preg_match( '/\(([^()]+)\)/', $clause, $matches );

			if ( isset( $matches ) && ! empty( $matches ) ) {
				$inner_clause  = $matches[1];
				$php_condition = self::parse_conditions( $inner_clause );
				$clause        = str_replace( $matches[0], "($php_condition)", $clause );
			}

			// Split by AND/OR, preserving operators.
			$conditions     = preg_split( '/\s+(AND|OR)\s+/i', $clause, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
			$php_conditions = array();
			$operator       = '&&'; // Default to AND.

			foreach ( $conditions as $condition ) {
				$condition = trim( $condition );

				if ( preg_match( '/^(.*?)\s*(LIKE|=|!=|<>|>|<|>=|<=|IN\(|IN \(|BETWEEN|IS NULL)\s*(.*)$/i', $condition, $matches ) ) {
					$field    = trim( $matches[1] );
					$op       = strtoupper( trim( $matches[2] ) );
					$value    = trim( $matches[3] );
					$var_name = 'var_' . uniqid();

					switch ( $op ) {
						case 'LIKE':
							// Handle LIKE values.
							if ( preg_match( '/^\'(.*)\'$/', $value, $value_matches ) ) {
								$php_conditions[] = "strpos($field, $var_name) !== false"; // Simulate LIKE.
								$value            = "'" . addslashes( $value_matches[1] ) . "'";
							}
							echo "\$$var_name = $value;\n";
							break;

						case 'IN':
							// Handle IN clause.
							$value_array      = explode( ',', trim( $value, '() ' ) );
							$value_array      = array_map( 'trim', $value_array );
							$php_conditions[] = "$field IN (" . implode( ', ', array_map( fn( $v) => '$' . 'var_' . uniqid(), $value_array ) ) . ')';
							foreach ( $value_array as $v ) {
								echo '$var_' . uniqid() . ' = ' . addslashes( trim( $v, "'" ) ) . ";\n"; // Assigning variables.
							}
							break;

						case 'BETWEEN':
							// Handle BETWEEN clause.
							if ( preg_match( '/^\'(.*?)\'\s+AND\s+\'(.*?)\'$/', $value, $between_matches ) ) {
								$lower_bound      = 'var_' . uniqid();
								$upperBound       = 'var_' . uniqid();
								$php_conditions[] = "$field BETWEEN \$$lower_bound AND \$$upperBound";
								echo "\$$lower_bound = '" . addslashes( $between_matches[1] ) . "';\n";
								echo "\$$upperBound = '" . addslashes( $between_matches[2] ) . "';\n";
							}
							break;

						case 'IS NULL':
							// Handle IS NULL.
							$php_conditions[] = "$field IS NULL";
							break;

						default:
							// Handle other comparisons.
							if ( preg_match( '/^\'(.*)\'$/', $value, $value_matches ) ) {
								$php_conditions[] = "$field $op \$$var_name";
								$value            = "'" . addslashes( $value_matches[1] ) . "'";
							} else {
								$php_conditions[] = "$field $op \$$var_name";
							}
							echo "\$$var_name = $value;\n";
							break;
					}
				}
			}

			// Determine the logical operator based on the original clause.
			if ( stripos( $clause, 'OR' ) !== false ) {
				$operator = '||';
			}

			// Combine conditions into a single PHP statement.
			return '(' . implode( " $operator ", $php_conditions ) . ')';
		}

			// // Example usage
			// $sqlWhere     = "WHERE (name LIKE 'John%' OR name LIKE 'Doe%') AND (age > 30 OR status IN ('active', 'pending')) AND birthdate BETWEEN '1990-01-01' AND '2000-12-31' AND status IS NULL";
			// $phpStatement = convertSqlWhereToPhp( $sqlWhere );

			// echo 'PHP Statement: ' . $phpStatement . "\n";
			*/
	}
}
