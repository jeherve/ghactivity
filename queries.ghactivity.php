<?php
/**
 * Bunch of static functions which are fetching some data from DB.
 *
 * @since 2.0.0
 */
class GHActivity_Queries {

	/**
	 * Count Posts per event type.
	 *
	 * @since 1.1
	 *
	 * @param string       $date_start      Starting date range, using a strtotime compatible format.
	 * @param string       $date_end        End date range, using a strtotime compatible format.
	 * @param string       $person          Get stats for a specific GitHub username.
	 * @param string|array $repo            Get stats for a specific GitHub repo, or a list of repos.
	 * @param bool         $split_per_actor Split counts per actor.
	 *
	 * @return array       $count           Array of count of registered Event types.
	 */
	public static function count_posts_per_event_type( $date_start, $date_end, $person = '', $repo = '', $split_per_actor = false ) {
		$count = array();

		if ( empty( $person ) ) {
			$person = get_terms( array(
				'taxonomy'   => 'ghactivity_actor',
				'hide_empty' => false,
			) );

			$person = wp_list_pluck( $person, 'name' );
		} elseif ( is_string( $person ) ) {
			$person = esc_html( $person );
		} elseif ( is_array( $person ) ) {
			$person = $person;
		}

		if ( empty( $repo ) ) {
			$repo = get_terms( array(
				'taxonomy'   => 'ghactivity_repo',
				'hide_empty' => true,
				'fields'     => 'id=>slug',
			) );

			$repo = array_values( $repo );
		} elseif ( is_string( $repo ) ) {
			$repo = esc_html( $repo );
		} elseif ( is_array( $repo ) ) {
			$repo = $repo;
		}

		$args = array(
			'post_type'      => 'ghactivity_event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,  // Show all posts.
			'date_query'     => array(
				'after' => $date_start,
				'before' => $date_end,
				'inclusive' => true,
			),
			'tax_query'      => array(
				'relation' => 'AND',
				array(
					'taxonomy' => 'ghactivity_actor',
					'field'    => 'name',
					'terms'    => $person,
				),
				array(
					'taxonomy' => 'ghactivity_repo',
					'field'    => 'slug',
					'terms'    => $repo,
				),
			),
		);
		/**
		 * Filter WP Query arguments used to count Posts per event type.
		 *
		 * @since 1.2
		 *
		 * @param array $args Array of WP Query arguments.
		 */
		$args = apply_filters( 'ghactivity_count_posts_event_type_query_args', $args );

		// Start a Query.
		$query = new WP_Query( $args );

		while ( $query->have_posts() ) {
			$query->the_post();

			$terms = get_the_terms( $query->post->ID, 'ghactivity_event_type' );

			/**
			 * If we want to split the counts per actor,
			 * we need to create an multidimensional array,
			 * with counts for each person.
			 */
			if ( true === $split_per_actor ) {
				$actor = get_the_terms( $query->post->ID, 'ghactivity_actor' );
				if (
					$terms
					&& ! is_wp_error( $terms )
					&& $actor
					&& ! is_wp_error( $actor )
				) {
					// Get the person's name.
					foreach ( $actor as $a ) {
						$actor_name = esc_html( $a->name );
					}

					if ( ! isset( $count[ $actor_name ] ) ) {
						$count[ $actor_name ] = array();
					}
					foreach ( $terms as $term ) {
						if ( isset( $count[ $actor_name ][ $term->slug ] ) ) {
							$count[ $actor_name ][ $term->slug ]++;
						} else {
							$count[ $actor_name ][ $term->slug ] = 1;
						}

						if ( isset( $count[ $actor_name ]['total'] ) ) {
							$count[ $actor_name ]['total']++;
						} else {
							$count[ $actor_name ]['total'] = 1;
						}
					}
				}
			} else {
				if ( $terms && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						if ( isset( $count[ $term->slug ] ) ) {
							$count[ $term->slug ]++;
						} else {
							$count[ $term->slug ] = 1;
						}
					}
				}
			} // End if().

			/**
			 * Filter the final array of event types and matching counts after calculation.
			 *
			 * Allows one to add their own a action, matching a specific term or Query element.
			 *
			 * @since 1.3
			 *
			 * @param array $count Array of count of registered Event types.
			 */
			$count = apply_filters( 'ghactivity_count_posts_event_type_counts', $count, $query );

		} // End while().
		wp_reset_postdata();

		// Sort the actors by total descending.
		if ( true === $split_per_actor ) {
			uasort( $count, array( 'GHActivity_Queries', 'sort_totals' ) );
		}

		return (array) $count;
	}

	/**
	 * Custom function to sort our counts.
	 *
	 * @since 1.6.0
	 *
	 * @param int $a Total number of contributions.
	 * @param int $b Total number of contributions.
	 */
	private static function sort_totals( $a, $b ) {
		return $a['total'] < $b['total'];
	}

	/**
	 * Count number of commits.
	 *
	 * @since 1.1
	 *
	 * @param string $date_start Starting date range, using a strtotime compatible format.
	 * @param string $date_end   End date range, using a strtotime compatible format.
	 * @param string $person     Get stats for a specific GitHub username.
	 *
	 * @return int $count Number of commits during that time period.
	 */
	public static function count_commits( $date_start, $date_end, $person = '' ) {
		$count = 0;

		if ( empty( $person ) ) {
			$person = get_terms( array(
				'taxonomy'   => 'ghactivity_actor',
				'hide_empty' => false,
			) );

			$person = wp_list_pluck( $person, 'name' );
		} elseif ( is_array( $person ) ) {
			$person = $person;
		} else {
			$person = esc_html( $person );
		}

		$args = array(
			'post_type'      => 'ghactivity_event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,  // Show all posts.
			'meta_key'       => '_github_commits',
			'date_query'     => array(
				'after' => $date_start,
				'before' => $date_end,
				'inclusive' => true,
			),
			'tax_query'      => array(
				array(
					'taxonomy' => 'ghactivity_actor',
					'field'    => 'name',
					'terms'    => $person,
				),
			),
		);
		/**
		 * Filter WP Query arguments used to count the number of commits in a specific date range.
		 *
		 * @since 1.2
		 *
		 * @param array $args Array of WP Query arguments.
		 */
		$args = apply_filters( 'ghactivity_count_commits_query_args', $args );

		// Start a Query.
		$query = new WP_Query( $args );

		while ( $query->have_posts() ) {
			$query->the_post();

			$count = $count + get_post_meta( $query->post->ID, '_github_commits', true );

		}
		wp_reset_postdata();

		return (int) $count;
	}

	/**
	 * Count the number of repos where you were involved in a specific time period.
	 *
	 * @since 1.4
	 *
	 * @param string $date_start Starting date range, using a strtotime compatible format.
	 * @param string $date_end   End date range, using a strtotime compatible format.
	 * @param string $person     Get stats for a specific GitHub username.
	 *
	 * @return int $count Number of repos during that time period.
	 */
	public static function count_repos( $date_start, $date_end, $person = '' ) {
		$repos = array();

		if ( empty( $person ) ) {
			$person = get_terms( array(
				'taxonomy'   => 'ghactivity_actor',
				'hide_empty' => false,
			) );

			$person = wp_list_pluck( $person, 'name' );
		} elseif ( is_array( $person ) ) {
			$person = $person;
		} else {
			$person = esc_html( $person );
		}

		$args = array(
			'post_type'      => 'ghactivity_event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,  // Show all posts.
			'date_query'     => array(
				'after'     => $date_start,
				'before'    => $date_end,
				'inclusive' => true,
			),
			'tax_query'      => array(
				array(
					'taxonomy' => 'ghactivity_actor',
					'field'    => 'name',
					'terms'    => $person,
				),
			),
		);
		/**
		 * Filter WP Query arguments used to count the number of repos in a specific date range.
		 *
		 * @since 1.4
		 *
		 * @param array $args Array of WP Query arguments.
		 */
		$args = apply_filters( 'ghactivity_count_repos_query_args', $args );

		// Start a Query.
		$query = new WP_Query( $args );

		while ( $query->have_posts() ) {
			$query->the_post();

			$terms = get_the_terms( $query->post->ID, 'ghactivity_repo' );

			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( isset( $repos[ $term->slug ] ) ) {
						$repos[ $term->slug ]++;
					} else {
						$repos[ $term->slug ] = 1;
					}
				}
			}
		}
		wp_reset_postdata();

		return (int) count( $repos );
	}

	/**
	 * Usage: current_average_label_time('Automattic/jetpack', '[Status] Needs Review').
	 *
	 * @param string $repo_name name of the repo.
	 * @param string $label issue label.
	 */
	public static function current_average_label_time( $repo_name, $label ) {
		$dates = array();
		$slugs = array();
		$query = array(
			'taxonomy' => 'ghactivity_issues_labels',
			'name'     => $label,
		);
		$term  = get_terms( $query )[0];
		$meta  = get_term_meta( $term->term_id );
		foreach ( $meta as $repo_slug => $serialized ) {
			// count only issues from specific repo.
			if ( strpos( strtolower( $repo_slug ), strtolower( $repo_name ) ) === 0 ) {
				$issue_number = explode( '#', $repo_slug )[1];
				$post_id      = self::find_open_gh_issue( $repo_name, $issue_number );
				$label_ary    = unserialize( $serialized[0] );

				// We want to capture only opened, labeled issues.
				if ( $post_id && 'labeled' === $label_ary['status'] ) {
					$time                = time() - strtotime( $label_ary['labeled'] );
					$dates[]             = $time;
					$slugs[ $repo_slug ] = $time;
				}
			}
		}
		return array( (int) array_sum( $dates ) / count( $dates ), $slugs );
	}

	public static function find_open_gh_issue( $repo_name, $issue_number ) {
		$post_id      = null;
		$is_open_args = array(
			'post_type'      => 'ghactivity_issue',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'tax_query'      => array(
				'relation' => 'AND',
				array(
					'taxonomy' => 'ghactivity_issues_state',
					'field'    => 'name',
					'terms'    => 'open',
				),
				array(
					'taxonomy' => 'ghactivity_repo',
					'field'    => 'name',
					'terms'    => $repo_name,
				),
			),
			'meta_query' => array(
				array(
					'key'     => 'number',
					'value'   => $issue_number,
					'compare' => '=',
				),
			),
		);
		$query = new WP_Query( $is_open_args );
		if ( $query->have_posts() ) {
			$query->the_post();
			$post_id = $query->post->ID;
		}
		wp_reset_postdata();

		return $post_id;
	}

	/**
	 * Search for a exisiting `ghactivity_issue` post
	 * Return post_id if found, and null if not.
	 *
	 * @param string $repo_name name of the repo.
	 * @param int    $issue_number issue number.
	 *
	 * @return int $post_id ID of the post. Null if not found.
	 */
	public static function find_gh_issue( $repo_name, $issue_number ) {
		$post_id     = null;
		$is_new_args = array(
			'post_type'      => 'ghactivity_issue',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'tax_query'      => array(
				array(
					'taxonomy' => 'ghactivity_repo',
					'field'    => 'name',
					'terms'    => $repo_name,
				),
			),
			'meta_query' => array(
				array(
					'key'     => 'number',
					'value'   => $issue_number,
					'compare' => '=',
				),
			),
		);
		$query = new WP_Query( $is_new_args );
		if ( $query->have_posts() ) {
			$query->the_post();
			$post_id = $query->post->ID;
		}
		wp_reset_postdata();

		return $post_id;
	}

	public static function fetch_average_label_time( $repo_name, $label, $range = null ) {
		$slug = $repo_name . '#' . $label;
		$args = array(
			'post_type'      => 'gh_query_record',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'tax_query'      => array(
				array(
					'taxonomy' => 'ghactivity_query_label_slug',
					'field'    => 'name',
					'terms'    => $slug,
				),
			),
		);

		if ( isset( $range ) ) {
			$args['date_query'] = array(
				'after'     => $range[0],
				'before'    => $range[1],
				'inclusive' => true,
			);
		}

		// FIXME: Add caching
		$posts = get_posts( $args );

		function get_post_content( $post ) {
			return array(
				(int) $post->post_content,
				strtotime( $post->post_date ),
				get_post_meta( $post->ID, 'record_slugs', true ),
			);
		}
		return array_map( 'get_post_content', $posts );
	}
}