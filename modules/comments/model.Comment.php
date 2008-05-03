<?php
	/**
	 * Class: Comment
	 * The model for the Comments SQL table.
	 */
	class Comment extends Model {
		public $no_results = false;

		/**
		 * Function: __construct
		 * Grabs the specified comment and injects it into the <Comment> class.
		 *
		 * Parameters:
		 *     $comment_id - The comment's unique ID.
		 *     $options - An array of options:
		 *         where: A SQL query to grab the comment by.
		 *         params: Parameters to use for the "where" option.
		 *         read_from: An associative array of values to load into the <Comment> class.
		 */
		public function __construct($comment_id, $options = array()) {
			parent::grab($this, $comment_id, $options);
		}

		/**
		 * Function: create
		 * Attempts to create a comment using the passed information. If a Defensio API key is present, it will check it.
		 *
		 * Parameters:
		 *     $author - The name of the commenter.
		 *     $email - The commenter's email.
		 *     $url - The commenter's website.
		 *     $body - The comment.
		 *     $post_id - The ID of the <Post> they're commenting on.
		 *     $type - The type of comment. Optional, used for trackbacks/pingbacks.
		 */
		static function create($author, $email, $url, $body, $post_id, $type = null) {
			if (!self::user_can($post_id)) return;
			global $modules;

			$post = new Post($post_id);
			$config = Config::current();
			$route = Route::current();
			$visitor = Visitor::current();

			if (!$type) {
				$status = ($post->user_id == $visitor->id) ? "approved" : $config->default_comment_status ;
				$type = "comment";
			} else
				$status = $type;

			if (!empty($config->defensio_api_key)) {
				$comment = array("owner-url" => $config->url,
				                 "user-ip" => $_SERVER['REMOTE_ADDR'],
				                 "article-date" => when("Y/m/d", $post->created_at),
				                 "comment-author" => $author,
				                 "comment-type" => $type,
				                 "comment-content" => $body,
				                 "comment-author-email" => $email,
				                 "comment-author-url" => $url,
				                 "permalink" => $post->url(),
				                 "referrer" => $_SERVER['HTTP_REFERER'],
				                 "user-logged-in" => logged_in());
				$check_comment = $modules["comments"]->defensio->audit_comment($comment);

				if ($check_comment["spam"] == "true") {
					self::add($body, $author, $url, $email, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], "spam", $check_comment["signature"], datetime(), $post_id, $visitor->id);
					error(__("Spam Comment"), __("Your comment has been marked as spam. It will have to be approved before it will show up.", "comments"));
				} else {
					$id = self::add($body, $author, $url, $email, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $status, $check_comment["signature"], datetime(), $post_id, $visitor->id);
					if (isset($_POST['ajax']))
						exit("{ comment_id: ".$id." }");
					$route->redirect($post->url()."#comment_".$id);
				}
			} else {
				$id = self::add($body, $author, $url, $email, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $status, $check_comment["signature"], datetime(), $post_id, $visitor->id);
				if (isset($_POST['ajax']))
					exit("{ comment_id: ".$id." }");
				$route->redirect($post->url()."#comment_".$id);
			}
		}

		/**
		 * Function: add
		 * Adds a comment to the database.
		 *
		 * Parameters:
		 *     $body - The comment.
		 *     $author - The name of the commenter.
		 *     $url - The commenter's website.
		 *     $email - The commenter's email.
		 *     $ip - The commenter's IP address.
		 *     $agent - The commenter's user agent.
		 *     $status - The new comment's status.
		 *     $timestamp - The new comment's timestamp of creation.
		 *     $post_id - The ID of the <Post> they're commenting on.
		 *     $user_id - If the user is logged in, this should be their user ID. Optional.
		 *     $signature - Defensio's data signature of the comment, generated when it is checked if it's spam in <Comment.create>. Optional.
		 */
		static function add($body, $author, $url, $email, $ip, $agent, $status, $signature, $timestamp, $post_id, $user_id) {
			if (!empty($url)) # Add the http:// if it isn't there.
				if (!parse_url($url, PHP_URL_SCHEME))
					$url = "http://".$url;

			$sql = SQL::current();
			$sql->query("insert into `".$sql->prefix."comments`
			             (`body`, `author`, `author_url`, `author_email`, `author_ip`,
			              `author_agent`, `status`, `signature`, `created_at`, `post_id`, `user_id`)
			             values
			             (:body, :author, :author_url, :author_email, :author_ip,
			              :author_agent, :status, :signature, :created_at, :post_id, :user_id)",
			            array(
			                ":body" => $body,
			                ":author" => strip_tags($author),
			                ":author_url" => strip_tags($url),
			                ":author_email" => strip_tags($email),
			                ":author_ip" => ip2long($ip),
			                ":author_agent" => $agent,
			                ":status" => $status,
			                ":signature" => $signature,
			                ":created_at" => $timestamp,
			                ":post_id" => $post_id,
			                ":user_id"=> $user_id
			            ));
			$id = $sql->db->lastInsertId();

			Trigger::current()->call("add_comment", new self($id));
			return $id;
		}
		static function info($column, $comment_id = null) {
			return SQL::current()->select("comments", $column, "`id` = :id", "`id` desc", array(":id" => $comment_id))->fetchColumn();
		}
		public function edit_link($text = null, $before = null, $after = null){
			$visitor = Visitor::current();
			if (!$visitor->group()->can("edit_comment")) return;
			fallback($text, __("Edit"));
			$config = Config::current();
			echo $before.'<a href="'.$config->url.'/admin/?action=edit_comment&amp;id='.$this->id.'" title="Edit" class="comment_edit_link" id="comment_edit_'.$this->id.'">'.$text.'</a>'.$after;
		}
		public function delete_link($text = null, $before = null, $after = null){
			$visitor = Visitor::current();
			if (!$visitor->group()->can("delete_comment")) return;
			fallback($text, __("Delete"));
			$config = Config::current();
			echo $before.'<a href="'.$config->url.'/admin/?action=delete_comment&amp;id='.$this->id.'" title="Delete" class="comment_delete_link" id="comment_delete_'.$this->id.'">'.$text.'</a>'.$after;
		}
		public function author_link() {
			if ($this->author_url != "") # If a URL is set
				echo '<a href="'.$this->author_url.'">'.$this->author.'</a>';
			else # If not, just show their name
				echo $this->author;
		}
		public function update($author, $author_email, $author_url, $body, $status, $timestamp) {
			$sql = SQL::current();
			$sql->query("update `".$sql->prefix."comments`
			             set
			                 `author` = :author,
			                 `author_email` = :author_email,
			                 `author_url` = :author_url,
			                 `body` = :body,
			                 `status` = :status,
			                 `created_at` = :created_at
			             where `id` = :id",
			            array(
			                ":author" => $author,
			                ":author_email" => $author_email,
			                ":author_url" => $author_url,
			                ":body" => $body,
			                ":status" => $status,
			                ":created_at" => $timestamp,
			                ":id" => $this->id
			            ));
			$trigger = Trigger::current();
			$trigger->call("update_comment", $this);
		}
		static function delete($comment_id) {
			$trigger = Trigger::current();
			if ($trigger->exists("delete_comment"))
				$trigger->call("delete_comment", new self($comment_id));

			$sql = SQL::current();
			$sql->query("delete from `".$sql->prefix."comments`
			             where `id` = :id",
			            array(
			                ":id" => $comment_id
			            ));
		}
		static function user_can($post_id) {
			$visitor = Visitor::current();
			if (!$visitor->group()->can("add_comment")) return false;

			$post = new Post($post_id, array("filter" => false));
			// assume allowed comments by default
			return empty($post->comment_status) or
			       !($post->comment_status == "closed" or
			        ($post->comment_status == "registered_only" and !logged_in()) or
			        ($post->comment_status == "private" and !$visitor->group()->can("add_comment_private")));
		}
		static function user_count($user_id) {
			$sql = SQL::current();
			$count = $sql->query("select count(`id`) from `".$sql->prefix."comments`
			                      where `user_id` = :user_id",
			                     array(
			                         ":user_id" => $user_id
			                     ));
			return $count->fetchColumn();
		}
		static function post_count($post_id) {
			$sql = SQL::current();
			$count = $sql->query("select count(`id`) from `".$sql->prefix."comments`
			                      where `post_id` = :post_id and (
	                                  `status` != 'denied' or (
                                          `status` = 'denied' and (
                                              `author_ip` = :current_ip or
                                              `user_id` = :user_id
                                          )
                                      )
                                  ) and
                                  `status` != 'spam'",
			                     array(
			                         ":post_id" => $post_id,
		                             ":current_ip" => ip2long($_SERVER['REMOTE_ADDR']),
		                             ":user_id" => Visitor::current()->id
			                     ));
			return $count->fetchColumn();
		}
		public function post() {
			return new Post($this->post_id);
		}
		public function user() {
			if ($this->user_id)
				return new User($this->user_id);
			else
				return Visitor::current();
		}
	}
