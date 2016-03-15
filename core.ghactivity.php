<?php
/**
 * GHActivity calls to GitHub API
 *
 * https://developer.github.com/v3/
 *
 * @since 1.0
 */

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * GitHub API Calls
 *
 * @since 1.0
 */
class GHActivity_Calls {

	function __construct() {
		add_action( 'ghactivity_publish', array( $this, 'publish_event' ) );
		if ( ! wp_next_scheduled( 'ghactivity_publish' ) ) {
			wp_schedule_event( time(), 'hourly', 'ghactivity_publish' );
		}
	}

	/**
	 * Get option saved in the plugin's settings screen.
	 *
	 * @since 1.0
	 *
	 * @param string $name Option name.
	 *
	 * @return Specific option
	 */
	private function get_option( $name ) {
		$options = get_option( 'ghactivity' );

		return $options[ $name ];
	}

	/**
	 * Remote call to get data from GitHub's API.
	 *
	 * @since 1.0
	 *
	 * @return null|array
	 */
	private function get_github_activity() {

		$query_url = sprintf(
			'https://api.github.com/users/%1$s/events?client_id=%2$s&client_secret=%3$s',
			$this->get_option( 'username' ),
			$this->get_option( 'client_id' ),
			$this->get_option( 'client_secret' )
		);
		$data = wp_remote_get( esc_url_raw( $query_url ) );

		if (
			is_wp_error( $data )
			|| 200 != $data['response']['code']
			|| empty( $data['body'] )
		) {
			return;
		}

		$response_body = json_decode( $data['body'] );

		return $response_body;
	}

	/**
	 * Get an event name to use as a taxonomy, and in the post content.
	 *
	 * Starts from data collected with GitHub API, and displays a nice event type instead.
	 *
	 * @since 1.0
	 *
	 * @param string $event_type Event name returned by GitHub API.
	 * @param string $action Action taken during event, as returned by GitHub API.
	 *
	 * @return string $event_type Event name displayed in event_type taxonomy.
	 */
	private function get_event_type( $event_type, $action ) {
		if ( 'IssuesEvent' == $event_type ) {
			if ( 'closed' == $action ) {
				$event_type = __( 'Issue Closed', 'ghactivity' );
			} elseif ( 'created' == $action ) {
				$event_type = __( 'Issue Opened', 'ghactivity' );
			} else {
				$event_type = __( 'Issue touched', 'ghactivity' );
			}
		} elseif ( 'IssueCommentEvent' == $event_type ) {
			$event_type = __( 'Comment', 'ghactivity' );
		} elseif ( 'PullRequestReviewCommentEvent' == $event_type ) {
			$event_type = __( 'Reviewed a PR', 'ghactivity' );
		} elseif ( 'PushEvent' == $event_type ) {
			$event_type = __( 'Pushed a branch', 'ghactivity' );
		} elseif ( 'CreateEvent' == $event_type ) {
			$event_type = __( 'Created a tag', 'ghactivity' );
		} elseif ( 'ReleaseEvent' == $event_type ) {
			$event_type = __( 'Created a release', 'ghactivity' );
		} else {
			$event_type = __( 'Did something', 'ghactivity' );
		}

		return $event_type;
	}

	/**
	 * Publish GitHub Event.
	 *
	 * @since 1.0
	 */
	public function publish_event() {

		$github_events = $this->get_github_activity();

		/**
		 * Only go through the event list if we have valid event array.
		 */
		if ( isset( $github_events ) && is_array( $github_events ) ) {

			foreach( $github_events as $event ) {
				// If no post exists with that ID, let's go on and publish a post.
				if ( is_null( get_page_by_title( $event->id, OBJECT, 'ghactivity_event' ) ) ) {

					// Store the number of commits attached to the event in post meta.
					if ( 'PushEvent' == $event->type ) {
						$meta = array( '_github_commits' => absint( $event->payload->distinct_size ) );
					} else {
						$meta = false;
					}

					// Avoid errors when no action is attached to the event.
					if ( isset( $event->payload->action ) ) {
						$action = $event->payload->action;
					} else {
						$action = '';
					}

					// Create taxonomies
					$taxonomies = array(
						'ghactivity_event_type' => esc_html( $this->get_event_type( $event->type, $action ) ),
						'ghactivity_repo' => esc_html( $event->repo->name ),
					);

					// Build Post Content.
					$post_content = sprintf(
						/* translators: %1$s is an action taken, %2$s is a number of commits. */
						__( '%1$s, including %2$s commits.', 'ghactivity' ),
						esc_html( $this->get_event_type( $event->type, $action ) ),
						( $meta ? $meta['_github_commits'] : 'no' )
					);

					$event_args = array(
						'post_title'   => $event->id,
						'post_type'    => 'ghactivity_event',
						'post_status'  => 'publish',
						'post_date'    => $event->created_at,
						'tax_input'    => $taxonomies,
						'meta_input'   => $meta,
						'post_content' => $post_content,
					);
					$post_id = wp_insert_post( $event_args );
					wp_set_object_terms(
						$post_id, $taxonomies['ghactivity_event_type'], 'ghactivity_event_type', true
					);
					wp_set_object_terms(
						$post_id, $taxonomies['ghactivity_repo'], 'ghactivity_repo', true
					);
				}
			}

		}
	}
}
new GHActivity_Calls();
