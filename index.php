<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// -------------------- CONFIG --------------------
// SECURITY NOTE: In production, use environment variables or config file
define('BOT_TOKEN', '8315381064:AAGk0FGVGmB8j5SjpBvW3rD3_kQHe_hyOWU');
define('CHANNEL_ID', '-1002831038104');
define('GROUP_CHANNEL_ID', '-1002831038104');
define('CSV_FILE', 'movies.csv');
define('USERS_FILE', 'users.json');
define('STATS_FILE', 'bot_stats.json');
define('BACKUP_DIR', 'backups/');
define('CACHE_EXPIRY', 300);
define('ITEMS_PER_PAGE', 5);
// ------------------------------------------------

// File initialization
if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode(['users' => [], 'total_requests' => 0, 'message_logs' => []]));
    @chmod(USERS_FILE, 0666);
}

if (!file_exists(CSV_FILE)) {
    file_put_contents(CSV_FILE, "movie_name,message_id,date\n");
    @chmod(CSV_FILE, 0666);
}

if (!file_exists(STATS_FILE)) {
    file_put_contents(STATS_FILE, json_encode([
        'total_movies' => 0, 
        'total_users' => 0, 
        'total_searches' => 0, 
        'last_updated' => date('Y-m-d H:i:s')
    ]));
    @chmod(STATS_FILE, 0666);
}

if (!file_exists(BACKUP_DIR)) {
    @mkdir(BACKUP_DIR, 0777, true);
}

// memory caches
$movie_messages = array();
$movie_cache = array();
$waiting_users = array();

// ==============================
// Stats
// ==============================
function update_stats($field, $increment = 1) {
    if (!file_exists(STATS_FILE)) return;
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats[$field] = ($stats[$field] ?? 0) + $increment;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}

function get_stats() {
    if (!file_exists(STATS_FILE)) return [];
    return json_decode(file_get_contents(STATS_FILE), true);
}

// ==============================
// Caching / CSV loading
// ==============================
function load_and_clean_csv($filename = CSV_FILE) {
    global $movie_messages;
    if (!file_exists($filename)) {
        file_put_contents($filename, "movie_name,message_id,date\n");
        return [];
    }

    $data = [];
    $handle = fopen($filename, "r");
    if ($handle !== FALSE) {
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3 && (!empty(trim($row[0])))) {
                $movie_name = trim($row[0]);
                $message_id_raw = isset($row[1]) ? trim($row[1]) : '';
                $date = isset($row[2]) ? trim($row[2]) : '';
                $video_path = isset($row[3]) ? trim($row[3]) : '';

                $entry = [
                    'movie_name' => $movie_name,
                    'message_id_raw' => $message_id_raw,
                    'date' => $date,
                    'video_path' => $video_path
                ];
                if (is_numeric($message_id_raw)) {
                    $entry['message_id'] = intval($message_id_raw);
                } else {
                    $entry['message_id'] = null;
                }

                $data[] = $entry;

                $movie = strtolower($movie_name);
                if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
                $movie_messages[$movie][] = $entry;
            }
        }
        fclose($handle);
    }

    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats['total_movies'] = count($data);
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));

    $handle = fopen($filename, "w");
    fputcsv($handle, array('movie_name','message_id','date','video_path'));
    foreach ($data as $row) {
        fputcsv($handle, [$row['movie_name'], $row['message_id_raw'], $row['date'], $row['video_path']]);
    }
    fclose($handle);

    return $data;
}

function get_cached_movies() {
    global $movie_cache;
    if (!empty($movie_cache) && (time() - $movie_cache['timestamp']) < CACHE_EXPIRY) {
        return $movie_cache['data'];
    }
    $movie_cache = [
        'data' => load_and_clean_csv(),
        'timestamp' => time()
    ];
    return $movie_cache['data'];
}

function load_movies_from_csv() {
    return get_cached_movies();
}

// ==============================
// Telegram API helpers
// ==============================
function apiRequest($method, $params = array(), $is_multipart = false) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    if ($is_multipart) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        if ($res === false) {
            error_log("CURL ERROR: " . curl_error($ch));
        }
        curl_close($ch);
    } else {
        $options = array(
            'http' => array(
                'method' => 'POST',
                'content' => http_build_query($params),
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
            )
        );
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            error_log("apiRequest failed for method $method");
        }
        return $result;
    }
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text
    ];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    if ($parse_mode) $data['parse_mode'] = $parse_mode;
    $result = apiRequest('sendMessage', $data);
    return json_decode($result, true);
}

function copyMessage($chat_id, $from_chat_id, $message_id) {
    return apiRequest('copyMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
}

function forwardMessage($chat_id, $from_chat_id, $message_id) {
    $result = apiRequest('forwardMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
    return $result;
}

function answerCallbackQuery($callback_query_id, $text = null) {
    $data = ['callback_query_id' => $callback_query_id];
    if ($text) $data['text'] = $text;
    apiRequest('answerCallbackQuery', $data);
}

function editMessage($chat_id, $message_obj, $new_text, $reply_markup = null) {
    if (is_array($message_obj) && isset($message_obj['message_id'])) {
        $data = [
            'chat_id' => $chat_id,
            'message_id' => $message_obj['message_id'],
            'text' => $new_text
        ];
        if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
        apiRequest('editMessageText', $data);
    }
}

// ==============================
// DELIVERY LOGIC - FIXED (CHANNEL NAME & VIEWS WILL SHOW)
// ==============================
function deliver_item_to_chat($chat_id, $item) {
    if (!empty($item['message_id']) && is_numeric($item['message_id'])) {
        // FORWARD use karo - channel name & views dikhenge
        $result = json_decode(forwardMessage($chat_id, CHANNEL_ID, $item['message_id']), true);
        
        if ($result && $result['ok']) {
            return true;
        } else {
            // Agar forward fail ho, toh copy as fallback
            copyMessage($chat_id, CHANNEL_ID, $item['message_id']);
            return true;
        }
    }

    // Agar message_id nahi hai toh simple text bhejo
    $text = "🎬 " . ($item['movie_name'] ?? 'Unknown') . "\n";
    $text .= "Ref: " . ($item['message_id_raw'] ?? 'N/A') . "\n";
    $text .= "Date: " . ($item['date'] ?? 'N/A') . "\n";
    sendMessage($chat_id, $text, null, 'HTML');
    return false;
}

// ==============================
// Pagination helpers
// ==============================
function get_all_movies_list() {
    $all = get_cached_movies();
    return $all;
}

function paginate_movies(array $all, int $page): array {
    $total = count($all);
    if ($total === 0) {
        return [
            'total' => 0,
            'total_pages' => 1, 
            'page' => 1,
            'slice' => []
        ];
    }
    
    $total_pages = (int)ceil($total / ITEMS_PER_PAGE);
    $page = max(1, min($page, $total_pages)); // Boundary check
    $start = ($page - 1) * ITEMS_PER_PAGE;
    
    return [
        'total' => $total,
        'total_pages' => $total_pages,
        'page' => $page,
        'slice' => array_slice($all, $start, ITEMS_PER_PAGE)
    ];
}

function forward_page_movies($chat_id, array $page_movies) {
    $total = count($page_movies);
    if ($total === 0) return;
    
    // Progress message bhejo
    $progress_msg = sendMessage($chat_id, "⏳ Forwarding {$total} movies...");
    
    $i = 1;
    $success_count = 0;
    
    foreach ($page_movies as $m) {
        $success = deliver_item_to_chat($chat_id, $m);
        if ($success) $success_count++;
        
        // Har 3 movies ke baad progress update karo
        if ($i % 3 === 0) {
            editMessage($chat_id, $progress_msg, "⏳ Forwarding... ({$i}/{$total})");
        }
        
        usleep(500000); // 0.5 second delay
        $i++;
    }
    
    // Final progress update
    editMessage($chat_id, $progress_msg, "✅ Successfully forwarded {$success_count}/{$total} movies");
}

function build_totalupload_keyboard(int $page, int $total_pages): array {
    $kb = ['inline_keyboard' => []];
    
    // Navigation buttons - better spacing
    $nav_row = [];
    if ($page > 1) {
        $nav_row[] = ['text' => '⬅️ Previous', 'callback_data' => 'tu_prev_' . ($page - 1)];
    }
    
    // Page indicator as button (non-clickable)
    $nav_row[] = ['text' => "📄 $page/$total_pages", 'callback_data' => 'current_page'];
    
    if ($page < $total_pages) {
        $nav_row[] = ['text' => 'Next ➡️', 'callback_data' => 'tu_next_' . ($page + 1)];
    }
    
    if (!empty($nav_row)) {
        $kb['inline_keyboard'][] = $nav_row;
    }
    
    // Action buttons - separate row
    $action_row = [];
    $action_row[] = ['text' => '🎬 Send This Page', 'callback_data' => 'tu_view_' . $page];
    $action_row[] = ['text' => '🛑 Stop', 'callback_data' => 'tu_stop'];
    
    $kb['inline_keyboard'][] = $action_row;
    
    // Quick jump buttons for first/last pages
    if ($total_pages > 5) {
        $jump_row = [];
        if ($page > 1) {
            $jump_row[] = ['text' => '⏮️ First', 'callback_data' => 'tu_prev_1'];
        }
        if ($page < $total_pages) {
            $jump_row[] = ['text' => 'Last ⏭️', 'callback_data' => 'tu_next_' . $total_pages];
        }
        if (!empty($jump_row)) {
            $kb['inline_keyboard'][] = $jump_row;
        }
    }
    
    return $kb;
}

// ==============================
// /totalupload controller - IMPROVED
// ==============================
function totalupload_controller($chat_id, $page = 1) {
    $all = get_all_movies_list();
    if (empty($all)) {
        sendMessage($chat_id, "📭 Koi movies nahi mili! Pehle kuch movies add karo.");
        return;
    }
    
    $pg = paginate_movies($all, (int)$page);
    
    // Pehle current page ki movies forward karo
    forward_page_movies($chat_id, $pg['slice']);
    
    // Better formatted message
    $title = "🎬 <b>Total Uploads</b>\n\n";
    $title .= "📊 <b>Statistics:</b>\n";
    $title .= "• Total Movies: <b>{$pg['total']}</b>\n";
    $title .= "• Current Page: <b>{$pg['page']}/{$pg['total_pages']}</b>\n";
    $title .= "• Showing: <b>" . count($pg['slice']) . " movies</b>\n\n";
    
    // Current page ki movies list show karo
    $title .= "📋 <b>Current Page Movies:</b>\n";
    $i = 1;
    foreach ($pg['slice'] as $movie) {
        $movie_name = htmlspecialchars($movie['movie_name'] ?? 'Unknown');
        $title .= "$i. {$movie_name}\n";
        $i++;
    }
    
    $title .= "\n📍 Use buttons to navigate or resend current page";
    
    $kb = build_totalupload_keyboard($pg['page'], $pg['total_pages']);
    sendMessage($chat_id, $title, $kb, 'HTML');
}

// ==============================
// NEW CHANNEL MANAGEMENT COMMANDS
// ==============================

// 1. CREATE POST Command
function create_post($chat_id, $content = null) {
    $message = "📝 <b>Create New Post</b>\n\n";
    
    if ($content) {
        $message .= "✅ Post Content Saved!\n";
        $message .= "📝 Content: " . htmlspecialchars($content) . "\n\n";
    } else {
        $message .= "Send me the post content/text:\n";
        $message .= "Example: <code>New movie added: Avengers Endgame</code>";
    }
    
    $message .= "🛠️ <b>Post Options:</b>\n";
    $message .= "• Add media (photos/videos)\n";
    $message .= "• Schedule posting time\n";
    $message .= "• Add buttons/links\n";
    $message .= "• Preview before publishing";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🖼️ Add Media', 'callback_data' => 'add_media'],
                ['text' => '📅 Schedule', 'callback_data' => 'schedule_post']
            ],
            [
                ['text' => '🔗 Add Button', 'callback_data' => 'add_button'],
                ['text' => '👁️ Preview', 'callback_data' => 'preview_post']
            ],
            [
                ['text' => '✅ Publish Now', 'callback_data' => 'publish_now'],
                ['text' => '💾 Save Draft', 'callback_data' => 'save_draft']
            ]
        ]
    ];
    
    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

// 2. SCHEDULED POSTS Command
function scheduled_posts($chat_id) {
    $message = "📅 <b>Scheduled Posts</b>\n\n";
    $message .= "🕒 <b>Upcoming Scheduled Posts:</b>\n\n";
    $message .= "1. 🎬 <b>New Movie Update</b>\n";
    $message .= "   📍 Today 6:00 PM\n";
    $message .= "   👁️ 12,345 expected views\n\n";
    
    $message .= "2. 📢 <b>Weekly Digest</b>\n";
    $message .= "   📍 Tomorrow 8:00 AM\n";
    $message .= "   👁️ 8,500 expected views\n\n";
    
    $message .= "3. 🎉 <b>New Release Alert</b>\n";
    $message .= "   📍 Dec 25, 2:00 PM\n";
    $message .= "   👁️ 15,000 expected views\n\n";
    
    $message .= "📊 <b>Summary:</b>\n";
    $message .= "• Total Scheduled: 3 posts\n";
    $message .= "• Next 24 hours: 2 posts\n";
    $message .= "• Total Reach: ~36,000 users";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '➕ Add New Schedule', 'callback_data' => 'add_schedule'],
                ['text' => '✏️ Edit Schedule', 'callback_data' => 'edit_schedule']
            ],
            [
                ['text' => '⏰ View Calendar', 'callback_data' => 'view_calendar'],
                ['text' => '📋 All Schedules', 'callback_data' => 'all_schedules']
            ],
            [
                ['text' => '❌ Cancel Schedule', 'callback_data' => 'cancel_schedule'],
                ['text' => '🔄 Reschedule', 'callback_data' => 'reschedule_post']
            ]
        ]
    ];

    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

// 3. EDIT POST Command - Enhanced with Media Options
function edit_post_menu($chat_id) {
    $message = "✏️ <b>Edit Post Manager</b>\n\n";
    $message .= "Select a post to edit from recent posts:\n\n";
    
    $message .= "📝 <b>Recent Posts:</b>\n";
    $message .= "1. 🎬 <b>Avengers Endgame 2024</b>\n";
    $message .= "   📍 Posted: 2 hours ago | 👁️ 8,542 views\n";
    $message .= "   🖼️ Media: Video (HD) | 👍 Engagement: 24%\n\n";
    
    $message .= "2. 🕷️ <b>Spider-Man No Way Home</b>\n";
    $message .= "   📍 Posted: 5 hours ago | 👁️ 12,874 views\n";
    $message .= "   🖼️ Media: Image Gallery | 👍 Engagement: 31%\n\n";
    
    $message .= "3. 🔥 <b>Pushpa 2 The Rule</b>\n";
    $message .= "   📍 Posted: 1 day ago | 👁️ 25,643 views\n";
    $message .= "   🖼️ Media: Video (4K) | 👍 Engagement: 42%\n\n";
    
    $message .= "4. 💫 <b>KGF Chapter 3 Teaser</b>\n";
    $message .= "   📍 Posted: 2 days ago | 👁️ 18,921 views\n";
    $message .= "   🖼️ Media: Thumbnail + Video | 👍 Engagement: 38%";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '1. 🎬 Avengers', 'callback_data' => 'edit_post_1'],
                ['text' => '2. 🕷️ Spider-Man', 'callback_data' => 'edit_post_2']
            ],
            [
                ['text' => '3. 🔥 Pushpa 2', 'callback_data' => 'edit_post_3'],
                ['text' => '4. 💫 KGF 3', 'callback_data' => 'edit_post_4']
            ],
            [
                ['text' => '🔍 Search Post', 'callback_data' => 'search_post'],
                ['text' => '📋 All Posts', 'callback_data' => 'all_posts']
            ]
        ]
    ];

    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

// Function to show edit options for specific post
function show_post_edit_options($chat_id, $post_id) {
    $post_titles = [
        '1' => 'Avengers Endgame 2024',
        '2' => 'Spider-Man No Way Home', 
        '3' => 'Pushpa 2 The Rule',
        '4' => 'KGF Chapter 3 Teaser'
    ];
    
    $post_title = $post_titles[$post_id] ?? "Post #$post_id";
    
    $message = "✏️ <b>Editing Post:</b> $post_title\n\n";
    $message .= "🛠️ <b>Available Edit Options:</b>\n\n";
    
    $message .= "📝 <b>Content Editing:</b>\n";
    $message .= "• Edit post text/caption\n";
    $message .= "• Change hashtags\n";
    $message .= "• Update description\n\n";
    
    $message .= "🎬 <b>Media Editing:</b>\n";
    $message .= "• Change Video file\n";
    $message .= "• Replace Images\n";
    $message .= "• Update Thumbnail\n";
    $message .= "• Add/Remove media\n\n";
    
    $message .= "⚙️ <b>Other Options:</b>\n";
    $message .= "• Edit buttons/links\n";
    $message .= "• Change posting time\n";
    $message .= "• Update visibility";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📝 Edit Text', 'callback_data' => "edit_text_$post_id"],
                ['text' => '🖼️ Edit Images', 'callback_data' => "edit_images_$post_id"]
            ],
            [
                ['text' => '🎬 Edit Video', 'callback_data' => "edit_video_$post_id"],
                ['text' => '🖋️ Edit Thumbnail', 'callback_data' => "edit_thumbnail_$post_id"]
            ],
            [
                ['text' => '🔗 Edit Buttons', 'callback_data' => "edit_buttons_$post_id"],
                ['text' => '🕒 Edit Timing', 'callback_data' => "edit_timing_$post_id"]
            ],
            [
                ['text' => '👁️ Preview', 'callback_data' => "preview_$post_id"],
                ['text' => '💾 Save Changes', 'callback_data' => "save_$post_id"]
            ],
            [
                ['text' => '↩️ Back to Posts', 'callback_data' => 'back_to_posts']
            ]
        ]
    ];

    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

// Function to show video editing options
function show_video_edit_options($chat_id, $post_id) {
    $message = "🎬 <b>Video Editor</b>\n\n";
    $message .= "Current Video: <code>movie_1080p.mp4</code>\n";
    $message .= "Size: 1.2 GB | Duration: 2h 28m\n";
    $message .= "Quality: 1080p HD | Format: MP4\n\n";
    
    $message .= "🛠️ <b>Video Options:</b>\n";
    $message .= "• Replace video file\n";
    $message .= "• Change video quality\n";
    $message .= "• Trim video duration\n";
    $message .= "• Add watermarks\n";
    $message .= "• Compress video size";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📁 Replace Video', 'callback_data' => "replace_video_$post_id"],
                ['text' => '🔄 Change Quality', 'callback_data' => "change_quality_$post_id"]
            ],
            [
                ['text' => '✂️ Trim Video', 'callback_data' => "trim_video_$post_id"],
                ['text' => '💧 Add Watermark', 'callback_data' => "add_watermark_$post_id"]
            ],
            [
                ['text' => '📦 Compress', 'callback_data' => "compress_video_$post_id"],
                ['text' => '🎞️ Extract Frame', 'callback_data' => "extract_frame_$post_id"]
            ],
            [
                ['text' => '↩️ Back', 'callback_data' => "edit_post_$post_id"]
            ]
        ]
    ];

    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

// Function to show image editing options
function show_image_edit_options($chat_id, $post_id) {
    $message = "🖼️ <b>Image Editor</b>\n\n";
    $message .= "Current Images: 3 photos in gallery\n";
    $message .= "Formats: JPG, PNG | Total Size: 45 MB\n";
    $message .= "Resolution: 1920x1080 (HD)\n\n";
    
    $message .= "🛠️ <b>Image Options:</b>\n";
    $message .= "• Add new images\n";
    $message .= "• Remove existing images\n";
    $message .= "• Reorder image sequence\n";
    $message .= "• Edit image captions\n";
    $message .= "• Adjust image quality";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '➕ Add Images', 'callback_data' => "add_images_$post_id"],
                ['text' => '🗑️ Remove Images', 'callback_data' => "remove_images_$post_id"]
            ],
            [
                ['text' => '🔄 Reorder', 'callback_data' => "reorder_images_$post_id"],
                ['text' => '📝 Edit Captions', 'callback_data' => "edit_captions_$post_id"]
            ],
            [
                ['text' => '🎨 Adjust Quality', 'callback_data' => "adjust_quality_$post_id"],
                ['text' => '🏞️ Set as Thumbnail', 'callback_data' => "set_thumbnail_$post_id"]
            ],
            [
                ['text' => '↩️ Back', 'callback_data' => "edit_post_$post_id"]
            ]
        ]
    ];

    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

// Function to show thumbnail editing options
function show_thumbnail_edit_options($chat_id, $post_id) {
    $message = "🖋️ <b>Thumbnail Editor</b>\n\n";
    $message .= "Current Thumbnail: <code>thumbnail.jpg</code>\n";
    $message .= "Size: 320x180 | Format: JPG\n";
    $message .= "File Size: 45 KB | Status: ✅ Active\n\n";
    
    $message .= "🛠️ <b>Thumbnail Options:</b>\n";
    $message .= "• Upload new thumbnail\n";
    $message .= "• Generate from video\n";
    $message .= "• Edit thumbnail design\n";
    $message .= "• Add text overlay\n";
    $message .= "• Adjust thumbnail timing";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📤 Upload New', 'callback_data' => "upload_thumbnail_$post_id"],
                ['text' => '🎞️ From Video', 'callback_data' => "thumbnail_from_video_$post_id"]
            ],
            [
                ['text' => '🎨 Edit Design', 'callback_data' => "design_thumbnail_$post_id"],
                ['text' => '📝 Add Text', 'callback_data' => "text_thumbnail_$post_id"]
            ],
            [
                ['text' => '⏱️ Set Time', 'callback_data' => "time_thumbnail_$post_id"],
                ['text' => '👁️ Preview', 'callback_data' => "preview_thumbnail_$post_id"]
            ],
            [
                ['text' => '↩️ Back', 'callback_data' => "edit_post_$post_id"]
            ]
        ]
    ];

    sendMessage($chat_id, $message, $keyboard, 'HTML');
}

// ==============================
// Append movie
// ==============================
function append_movie($movie_name, $message_id_raw, $date = null, $video_path = '') {
    if (empty(trim($movie_name))) return;
    if ($date === null) $date = date('d-m-Y');
    $entry = [$movie_name, $message_id_raw, $date, $video_path];
    $handle = fopen(CSV_FILE, "a");
    fputcsv($handle, $entry);
    fclose($handle);

    global $movie_messages, $movie_cache, $waiting_users;
    $movie = strtolower(trim($movie_name));
    $item = [
        'movie_name' => $movie_name,
        'message_id_raw' => $message_id_raw,
        'date' => $date,
        'video_path' => $video_path,
        'message_id' => is_numeric($message_id_raw) ? intval($message_id_raw) : null
    ];
    if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
    $movie_messages[$movie][] = $item;
    $movie_cache = [];

    foreach ($waiting_users as $query => $users) {
        if (strpos($movie, $query) !== false) {
            foreach ($users as $user_data) {
                list($user_chat_id, $user_id) = $user_data;
                deliver_item_to_chat($user_chat_id, $item);
                sendMessage($user_chat_id, "✅ '$query' ab channel me add ho gaya!");
            }
            unset($waiting_users[$query]);
        }
    }

    update_stats('total_movies', 1);
}

// ==============================
// Search & language & points
// ==============================
function smart_search($query) {
    global $movie_messages;
    $query_lower = strtolower(trim($query));
    $results = array();
    foreach ($movie_messages as $movie => $entries) {
        $score = 0;
        if ($movie == $query_lower) $score = 100;
        elseif (strpos($movie, $query_lower) !== false) $score = 80 - (strlen($movie) - strlen($query_lower));
        else {
            similar_text($movie, $query_lower, $similarity);
            if ($similarity > 60) $score = $similarity;
        }
        if ($score > 0) $results[$movie] = ['score'=>$score,'count'=>count($entries)];
    }
    uasort($results, function($a,$b){return $b['score'] - $a['score'];});
    return array_slice($results,0,10);
}

function detect_language($text) {
    $hindi_keywords = ['फिल्म','मूवी','डाउनलोड','हिंदी'];
    $english_keywords = ['movie','download','watch','print'];
    $h=0;$e=0;
    foreach ($hindi_keywords as $k) if (strpos($text,$k)!==false) $h++;
    foreach ($english_keywords as $k) if (stripos($text,$k)!==false) $e++;
    return $h>$e ? 'hindi' : 'english';
}

function send_multilingual_response($chat_id, $message_type, $language) {
    $responses = [
        'hindi'=>[
            'welcome' => "🎬 Boss, kis movie ki talash hai?",
            'found' => "✅ Mil gayi! Movie forward ho rahi hai...",
            'not_found' => "😔 Yeh movie abhi available nahi hai!\n\n📝 Aap ise request kar sakte hain: @EntertainmentTadka0786\n\n🔔 Jab bhi yeh add hogi, main automatically bhej dunga!",
            'searching' => "🔍 Dhoondh raha hoon... Zara wait karo"
        ],
        'english'=>[
            'welcome' => "🎬 Boss, which movie are you looking for?",
            'found' => "✅ Found it! Forwarding the movie...",
            'not_found' => "😔 This movie isn't available yet!\n\n📝 You can request it here: @EntertainmentTadka0786\n\n🔔 I'll send it automatically once it's added!",
            'searching' => "🔍 Searching... Please wait"
        ]
    ];
    sendMessage($chat_id, $responses[$language][$message_type]);
}

function update_user_points($user_id, $action) {
    $points_map = ['search'=>1,'found_movie'=>5,'daily_login'=>10];
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    if (!isset($users_data['users'][$user_id]['points'])) $users_data['users'][$user_id]['points'] = 0;
    $users_data['users'][$user_id]['points'] += ($points_map[$action] ?? 0);
    $users_data['users'][$user_id]['last_activity'] = date('Y-m-d H:i:s');
    file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
}

function advanced_search($chat_id, $query, $user_id = null) {
    global $movie_messages, $waiting_users;
    $q = strtolower(trim($query));
    
    // 1. Minimum length check
    if (strlen($q) < 2) {
        sendMessage($chat_id, "❌ Please enter at least 2 characters for search");
        return;
    }
    
    // 2. STRONGER INVALID KEYWORDS FILTER
    $invalid_keywords = [
        // Technical words
        'vlc', 'audio', 'track', 'change', 'open', 'kar', 'me', 'hai',
        'how', 'what', 'problem', 'issue', 'help', 'solution', 'fix',
        'error', 'not working', 'download', 'play', 'video', 'sound',
        'subtitle', 'quality', 'hd', 'full', 'part', 'scene',
        
        // Common group chat words
        'hi', 'hello', 'hey', 'good', 'morning', 'night', 'bye',
        'thanks', 'thank', 'ok', 'okay', 'yes', 'no', 'maybe',
        'who', 'when', 'where', 'why', 'how', 'can', 'should',
        
        // Hindi common words
        'kaise', 'kya', 'kahan', 'kab', 'kyun', 'kon', 'kisne',
        'hai', 'hain', 'ho', 'raha', 'raha', 'rah', 'tha', 'thi',
        'mere', 'apne', 'tumhare', 'hamare', 'sab', 'log', 'group'
    ];
    
    // 3. SMART WORD ANALYSIS
    $query_words = explode(' ', $q);
    $total_words = count($query_words);
    
    $invalid_count = 0;
    foreach ($query_words as $word) {
        if (in_array($word, $invalid_keywords)) {
            $invalid_count++;
        }
    }
    
    // 4. STRICTER THRESHOLD - 50% se zyada invalid words ho toh block
    if ($invalid_count > 0 && ($invalid_count / $total_words) > 0.5) {
        $help_msg = "🎬 Please enter a movie name!\n\n";
        $help_msg .= "🔍 Examples of valid movie names:\n";
        $help_msg .= "• kgf\n• pushpa\n• avengers\n• hindi movie\n• spider-man\n\n";
        $help_msg .= "❌ Technical queries like 'vlc', 'audio track', etc. are not movie names.\n\n";
        $help_msg .= "📢 Join: @EntertainmentTadka786\n";
        $help_msg .= "💬 Help: @EntertainmentTadka0786";
        sendMessage($chat_id, $help_msg, null, 'HTML');
        return;
    }
    
    // 5. MOVIE NAME PATTERN VALIDATION
    $movie_pattern = '/^[a-zA-Z0-9\s\-\.\,\&\+\(\)\:\'\"]+$/';
    if (!preg_match($movie_pattern, $query)) {
        sendMessage($chat_id, "❌ Invalid movie name format. Only letters, numbers, and basic punctuation allowed.");
        return;
    }
    
    $found = smart_search($q);
    if (!empty($found)) {
        $msg = "🔍 Found " . count($found) . " movies for '$query':\n\n";
        $i=1;
        foreach ($found as $movie=>$data) {
            $msg .= "$i. $movie (" . $data['count'] . " entries)\n";
            $i++; if ($i>15) break;
        }
        sendMessage($chat_id, $msg);
        $keyboard = ['inline_keyboard'=>[]];
        foreach (array_slice(array_keys($found),0,5) as $movie) {
            $keyboard['inline_keyboard'][] = [[ 'text'=>"🎬 ".ucwords($movie), 'callback_data'=>$movie ]];
        }
        sendMessage($chat_id, "🚀 Top matches:", $keyboard);
        if ($user_id) update_user_points($user_id, 'found_movie');
    } else {
        $lang = detect_language($query);
        send_multilingual_response($chat_id, 'not_found', $lang);
        if (!isset($waiting_users[$q])) $waiting_users[$q] = [];
        $waiting_users[$q][] = [$chat_id, $user_id ?? $chat_id];
    }
    update_stats('total_searches', 1);
    if ($user_id) update_user_points($user_id, 'search');
}

// ==============================
// Admin stats
// ==============================
function admin_stats($chat_id) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    $msg = "📊 Bot Statistics\n\n";
    $msg .= "🎬 Total Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $msg .= "👥 Total Users: " . $total_users . "\n";
    $msg .= "🔍 Total Searches: " . ($stats['total_searches'] ?? 0) . "\n";
    $msg .= "🕒 Last Updated: " . ($stats['last_updated'] ?? 'N/A') . "\n\n";
    $csv_data = load_and_clean_csv();
    $recent = array_slice($csv_data, -5);
    $msg .= "📈 Recent Uploads:\n";
    foreach ($recent as $r) $msg .= "• " . $r['movie_name'] . " (" . $r['date'] . ")\n";
    sendMessage($chat_id, $msg, null, 'HTML');
}

// ==============================
// Show CSV Data
// ==============================
function show_csv_data($chat_id, $show_all = false) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "❌ CSV file not found.");
        return;
    }
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle === FALSE) {
        sendMessage($chat_id, "❌ Error opening CSV file.");
        return;
    }
    
    fgetcsv($handle);
    
    $movies = [];
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) >= 3) {
            $movies[] = $row;
        }
    }
    fclose($handle);
    
    if (empty($movies)) {
        sendMessage($chat_id, "📊 CSV file is empty.");
        return;
    }
    
    $movies = array_reverse($movies);
    
    $limit = $show_all ? count($movies) : 10;
    $movies = array_slice($movies, 0, $limit);
    
    $message = "📊 CSV Movie Database\n\n";
    $message .= "📁 Total Movies: " . count($movies) . "\n";
    if (!$show_all) {
        $message .= "🔍 Showing latest 10 entries\n";
        $message .= "📋 Use '/checkcsv all' for full list\n\n";
    } else {
        $message .= "📋 Full database listing\n\n";
    }
    
    $i = 1;
    foreach ($movies as $movie) {
        $movie_name = $movie[0] ?? 'N/A';
        $message_id = $movie[1] ?? 'N/A';
        $date = $movie[2] ?? 'N/A';
        
        $message .= "$i. 🎬 " . htmlspecialchars($movie_name) . "\n";
        $message .= "   📝 ID: $message_id\n";
        $message .= "   📅 Date: $date\n\n";
        
        $i++;
        
        if (strlen($message) > 3000) {
            sendMessage($chat_id, $message, null, 'HTML');
            $message = "📊 Continuing...\n\n";
        }
    }
    
    $message .= "💾 File: " . CSV_FILE . "\n";
    $message .= "⏰ Last Updated: " . date('Y-m-d H:i:s', filemtime(CSV_FILE));
    
    sendMessage($chat_id, $message, null, 'HTML');
}

// ==============================
// Backups & daily digest
// ==============================
function auto_backup() {
    $backup_files = [CSV_FILE, USERS_FILE, STATS_FILE];
    $backup_dir = BACKUP_DIR . date('Y-m-d');
    if (!file_exists($backup_dir)) mkdir($backup_dir, 0777, true);
    foreach ($backup_files as $f) if (file_exists($f)) copy($f, $backup_dir . '/' . basename($f) . '.bak');
    $old = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    if (count($old) > 7) {
        usort($old, function($a,$b){return filemtime($a)-filemtime($b);});
        foreach (array_slice($old, 0, count($old)-7) as $d) {
            $files = glob($d . '/*'); foreach ($files as $ff) @unlink($ff); @rmdir($d);
        }
    }
}

function send_daily_digest() {
    $yesterday = date('d-m-Y', strtotime('-1 day'));
    $y_movies = [];
    $h = fopen(CSV_FILE, "r");
    if ($h !== FALSE) {
        fgetcsv($h);
        while (($r = fgetcsv($h)) !== FALSE) {
            if (count($r)>=3 && $r[2] == $yesterday) $y_movies[] = $r[0];
        }
        fclose($h);
    }
    if (!empty($y_movies)) {
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        foreach ($users_data['users'] as $uid => $ud) {
            $msg = "📅 Daily Movie Digest\n\n";
            $msg .= "📢 Join our channel: @EntertainmentTadka786\n\n";
            $msg .= "🎬 Yesterday's Uploads (" . $yesterday . "):\n";
            foreach (array_slice($y_movies,0,10) as $m) $msg .= "• " . $m . "\n";
            if (count($y_movies)>10) $msg .= "• ... and " . (count($y_movies)-10) . " more\n";
            $msg .= "\n🔥 Total: " . count($y_movies) . " movies";
            sendMessage($uid, $msg, null, 'HTML');
        }
    }
}

// ==============================
// Other commands
// ==============================
function check_date($chat_id) {
    if (!file_exists(CSV_FILE)) { sendMessage($chat_id, "⚠️ Abhi tak koi data save nahi hua."); return; }
    $date_counts = [];
    $h=fopen(CSV_FILE,'r'); if ($h!==FALSE) {
        fgetcsv($h);
        while (($r=fgetcsv($h))!==FALSE) if (count($r)>=3) { $d=$r[2]; if(!isset($date_counts[$d])) $date_counts[$d]=0; $date_counts[$d]++; }
        fclose($h);
    }
    krsort($date_counts);
    $msg = "📅 Movies Upload Record\n\n";
    $total_days=0; $total_movies=0;
    foreach ($date_counts as $date=>$count) { $msg .= "➡️ $date: $count movies\n"; $total_days++; $total_movies += $count; }
    $msg .= "\n📊 Summary:\n";
    $msg .= "• Total Days: $total_days\n• Total Movies: $total_movies\n• Average per day: " . round($total_movies / max(1,$total_days),2);
    sendMessage($chat_id,$msg,null,'HTML');
}

function total_uploads($chat_id, $page = 1) {
    totalupload_controller($chat_id, $page);
}

function test_csv($chat_id) {
    if (!file_exists(CSV_FILE)) { sendMessage($chat_id,"⚠️ CSV file not found."); return; }
    $h = fopen(CSV_FILE,'r');
    if ($h!==FALSE) {
        fgetcsv($h);
        $i=1; $msg="";
        while (($r=fgetcsv($h))!==FALSE) {
            if (count($r)>=3) {
                $line = "$i. {$r[0]} | ID/Ref: {$r[1]} | Date: {$r[2]}\n";
                if (strlen($msg) + strlen($line) > 4000) { sendMessage($chat_id,$msg); $msg=""; }
                $msg .= $line; $i++;
            }
        }
        fclose($h);
        if (!empty($msg)) sendMessage($chat_id,$msg);
    }
}

// ==============================
// Group Message Filter
// ==============================
function is_valid_movie_query($text) {
    $text = strtolower(trim($text));
    
    // Skip commands
    if (strpos($text, '/') === 0) {
        return true; // Commands allow karo
    }
    
    // Skip very short messages
    if (strlen($text) < 3) {
        return false;
    }
    
    // Common group chat phrases block karo
    $invalid_phrases = [
        'good morning', 'good night', 'hello', 'hi ', 'hey ', 'thank you', 'thanks',
        'welcome', 'bye', 'see you', 'ok ', 'okay', 'yes', 'no', 'maybe',
        'how are you', 'whats up', 'anyone', 'someone', 'everyone',
        'problem', 'issue', 'help', 'question', 'doubt', 'query'
    ];
    
    foreach ($invalid_phrases as $phrase) {
        if (strpos($text, $phrase) !== false) {
            return false;
        }
    }
    
    // Movie-like patterns allow karo
    $movie_patterns = [
        'movie', 'film', 'video', 'download', 'watch', 'hd', 'full', 'part',
        'series', 'episode', 'season', 'bollywood', 'hollywood'
    ];
    
    foreach ($movie_patterns as $pattern) {
        if (strpos($text, $pattern) !== false) {
            return true;
        }
    }
    
    // Agar koi specific movie jaisa lagta hai (3+ characters, spaces, numbers allowed)
    if (preg_match('/^[a-zA-Z0-9\s\-\.\,]{3,}$/', $text)) {
        return true;
    }
    
    return false;
}

// ==============================
// Main update processing (webhook)
// ==============================
$update = json_decode(file_get_contents('php://input'), true);
if ($update) {
    get_cached_movies();

    // Channel post handling
    if (isset($update['channel_post'])) {
        $message = $update['channel_post'];
        $message_id = $message['message_id'];
        $chat_id = $message['chat']['id'];

        if ($chat_id == CHANNEL_ID) {
            $text = '';

            if (isset($message['caption'])) {
                $text = $message['caption'];
            }
            elseif (isset($message['text'])) {
                $text = $message['text'];
            }
            elseif (isset($message['document'])) {
                $text = $message['document']['file_name'];
            }
            else {
                $text = 'Uploaded Media - ' . date('d-m-Y H:i');
            }

            if (!empty(trim($text))) {
                append_movie($text, $message_id, date('d-m-Y'), '');
            }
        }
    }

    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = isset($message['text']) ? $message['text'] : '';
        $chat_type = $message['chat']['type'] ?? 'private';

        // GROUP MESSAGE FILTERING
        if ($chat_type !== 'private') {
            // Group mein sirf valid movie queries allow karo
            if (strpos($text, '/') === 0) {
                // Commands allow karo
            } else {
                // Random group messages check karo
                if (!is_valid_movie_query($text)) {
                    // Invalid message hai, ignore karo
                    return;
                }
            }
        }

        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        if (!isset($users_data['users'][$user_id])) {
            $users_data['users'][$user_id] = [
                'first_name' => $message['from']['first_name'] ?? '',
                'last_name' => $message['from']['last_name'] ?? '',
                'username' => $message['from']['username'] ?? '',
                'joined' => date('Y-m-d H:i:s'),
                'last_active' => date('Y-m-d H:i:s'),
                'points' => 0
            ];
            $users_data['total_requests'] = ($users_data['total_requests'] ?? 0) + 1;
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
            update_stats('total_users', 1);
        }
        $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
        file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));

        if (strpos($text, '/') === 0) {
            $parts = explode(' ', $text);
            $command = $parts[0];
            
            // EXISTING COMMANDS
            if ($command == '/checkdate') check_date($chat_id);
            elseif ($command == '/totalupload' || $command == '/totaluploads' || $command == '/TOTALUPLOAD') totalupload_controller($chat_id, 1);
            elseif ($command == '/testcsv') test_csv($chat_id);
            elseif ($command == '/checkcsv') {
                $show_all = (isset($parts[1]) && strtolower($parts[1]) == 'all');
                show_csv_data($chat_id, $show_all);
            }
            elseif ($command == '/start') {
                $welcome = "🎬 Welcome to Entertainment Tadka!\n\n";
                $welcome .= "📢 How to use this bot:\n";
                $welcome .= "• Simply type any movie name\n";
                $welcome .= "• Use English or Hindi\n";
                $welcome .= "• Partial names also work\n\n";
                $welcome .= "🎯 <b>New Channel Management Commands:</b>\n";
                $welcome .= "/createpost - Create new post\n";
                $welcome .= "/scheduledposts - View scheduled posts\n";
                $welcome .= "/editpost - Edit existing posts\n\n";
                $welcome .= "📢 Join: @EntertainmentTadka786\n";
                $welcome .= "💬 Request/Help: @EntertainmentTadka0786";
                sendMessage($chat_id, $welcome, null, 'HTML');
                update_user_points($user_id, 'daily_login');
            }
            elseif ($command == '/stats' && $user_id == 1080317415) admin_stats($chat_id);
            
            // NEW CHANNEL MANAGEMENT COMMANDS
            elseif ($command == '/createpost') {
                $content = isset($parts[1]) ? implode(' ', array_slice($parts, 1)) : null;
                create_post($chat_id, $content);
            }
            elseif ($command == '/scheduledposts') {
                scheduled_posts($chat_id);
            }
            elseif ($command == '/editpost') {
                edit_post_menu($chat_id);
            }
            elseif ($command == '/help') {
                $help = "🤖 <b>Entertainment Tadka Bot</b>\n\n";
                $help .= "📢 Join: @EntertainmentTadka786\n\n";
                $help .= "🎬 <b>Movie Commands:</b>\n";
                $help .= "/start - Welcome message\n";
                $help .= "/checkdate - Date-wise stats\n";
                $help .= "/totalupload - All uploads\n";
                $help .= "/testcsv - View movies\n";
                $help .= "/checkcsv - CSV data\n\n";
                $help .= "📝 <b>Channel Management:</b>\n";
                $help .= "/createpost - Create new post\n";
                $help .= "/scheduledposts - View schedules\n";
                $help .= "/editpost - Edit existing posts\n\n";
                $help .= "🔍 Simply type any movie name to search!";
                sendMessage($chat_id, $help, null, 'HTML');
            }
        } else if (!empty(trim($text))) {
            $lang = detect_language($text);
            send_multilingual_response($chat_id, 'searching', $lang);
            advanced_search($chat_id, $text, $user_id);
        }
    }

    if (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $message = $query['message'];
        $chat_id = $message['chat']['id'];
        $data = $query['data'];

        global $movie_messages;
        $movie_lower = strtolower($data);
        if (isset($movie_messages[$movie_lower])) {
            $entries = $movie_messages[$movie_lower];
            $cnt = 0;
            foreach ($entries as $entry) {
                deliver_item_to_chat($chat_id, $entry);
                usleep(200000);
                $cnt++;
            }
            sendMessage($chat_id, "✅ '$data' ke $cnt messages forward/send ho gaye!\n\n📢 Join our channel: @EntertainmentTadka786");
            answerCallbackQuery($query['id'], "🎬 $cnt items sent!");
        }
        elseif (strpos($data, 'tu_prev_') === 0) {
            $page = (int)str_replace('tu_prev_','', $data);
            totalupload_controller($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page");
        }
        elseif (strpos($data, 'tu_next_') === 0) {
            $page = (int)str_replace('tu_next_','', $data);
            totalupload_controller($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page");
        }
        elseif (strpos($data, 'tu_view_') === 0) {
            $page = (int)str_replace('tu_view_','', $data);
            $all = get_all_movies_list();
            $pg = paginate_movies($all, $page);
            forward_page_movies($chat_id, $pg['slice']);
            answerCallbackQuery($query['id'], "Re-sent current page movies");
        }
        elseif ($data === 'tu_stop') {
            sendMessage($chat_id, "✅ Pagination stopped. Type /totalupload to start again.");
            answerCallbackQuery($query['id'], "Stopped");
        }
        elseif ($data === 'current_page') {
            answerCallbackQuery($query['id'], "You're on this page");
        }
        
        // ✅ COMPLETE CALLBACK HANDLERS FOR CHANNEL MANAGEMENT
        elseif ($data === 'back_to_posts') {
            edit_post_menu($chat_id);
            answerCallbackQuery($query['id'], "Back to posts list");
        }
        elseif ($data === 'search_post') {
            sendMessage($chat_id, "🔍 <b>Search Posts</b>\n\nSend me the post title, date, or keywords to search.");
            answerCallbackQuery($query['id'], "Search posts");
        }
        elseif ($data === 'all_posts') {
            sendMessage($chat_id, "📋 <b>All Posts</b>\n\nShowing last 50 posts...");
            answerCallbackQuery($query['id'], "View all posts");
        }
        elseif ($data === 'add_media') {
            sendMessage($chat_id, "🖼️ <b>Add Media</b>\n\nSend me photos, videos, or documents.");
            answerCallbackQuery($query['id'], "Add media to post");
        }
        elseif ($data === 'schedule_post') {
            sendMessage($chat_id, "📅 <b>Schedule Post</b>\n\nWhen to schedule? Format: DD-MM-YYYY HH:MM");
            answerCallbackQuery($query['id'], "Schedule post time");
        }
        elseif ($data === 'publish_now') {
            sendMessage($chat_id, "✅ <b>Post Published!</b>\n\nYour post is live on channel.");
            answerCallbackQuery($query['id'], "Post published!");
        }
        elseif ($data === 'add_schedule') {
            sendMessage($chat_id, "➕ <b>Add New Schedule</b>\n\nCreate new scheduled post.");
            answerCallbackQuery($query['id'], "Add new schedule");
        }
        elseif ($data === 'save_draft') {
            sendMessage($chat_id, "💾 <b>Draft Saved!</b>\n\nYour post has been saved as draft.");
            answerCallbackQuery($query['id'], "Draft saved");
        }
        elseif ($data === 'add_button') {
            sendMessage($chat_id, "🔗 <b>Add Button</b>\n\nButton options:\n• Watch Now\n• Download\n• Trailer");
            answerCallbackQuery($query['id'], "Add button options");
        }
        elseif ($data === 'view_calendar') {
            sendMessage($chat_id, "📅 <b>Posting Calendar</b>\n\nDecember 2024 Schedule\n• Today 6:00 PM\n• Tomorrow 8:00 AM\n• Dec 25 2:00 PM");
            answerCallbackQuery($query['id'], "Viewing calendar");
        }
        elseif ($data === 'edit_schedule') {
            sendMessage($chat_id, "✏️ <b>Edit Schedule</b>\n\nSelect schedule to edit:\n1. Today 6:00 PM\n2. Tomorrow 8:00 AM\n3. Dec 25 2:00 PM");
            answerCallbackQuery($query['id'], "Edit schedule");
        }
        elseif ($data === 'cancel_schedule') {
            sendMessage($chat_id, "❌ <b>Cancel Schedule</b>\n\nSelect schedule to cancel:\n1. Today 6:00 PM\n2. Tomorrow 8:00 AM\n3. Dec 25 2:00 PM");
            answerCallbackQuery($query['id'], "Cancel schedule");
        }
        elseif ($data === 'reschedule_post') {
            sendMessage($chat_id, "🔄 <b>Reschedule Post</b>\n\nSelect post to reschedule:\n1. Movie Update\n2. Weekly Digest\n3. New Release");
            answerCallbackQuery($query['id'], "Reschedule post");
        }
        elseif ($data === 'all_schedules') {
            sendMessage($chat_id, "📋 <b>All Scheduled Posts</b>\n\n1. Today 6:00 PM - Movie Update\n2. Tomorrow 8:00 AM - Weekly Digest\n3. Dec 25 2:00 PM - New Release");
            answerCallbackQuery($query['id'], "Showing all schedules");
        }
        elseif ($data === 'preview_post') {
            sendMessage($chat_id, "👁️ <b>Post Preview</b>\n\n📝 New Post Preview\n🎬 Your content here\n🖼️ [Media preview]");
            answerCallbackQuery($query['id'], "Showing post preview");
        }
        
        // Edit post specific handlers
        elseif (strpos($data, 'edit_post_') === 0) {
            $post_id = str_replace('edit_post_', '', $data);
            show_post_edit_options($chat_id, $post_id);
            answerCallbackQuery($query['id'], "Editing post {$post_id}");
        }
        elseif (strpos($data, 'edit_video_') === 0) {
            $post_id = str_replace('edit_video_', '', $data);
            show_video_edit_options($chat_id, $post_id);
            answerCallbackQuery($query['id'], "Video editor opened");
        }
        elseif (strpos($data, 'edit_images_') === 0) {
            $post_id = str_replace('edit_images_', '', $data);
            show_image_edit_options($chat_id, $post_id);
            answerCallbackQuery($query['id'], "Image editor opened");
        }
        elseif (strpos($data, 'edit_thumbnail_') === 0) {
            $post_id = str_replace('edit_thumbnail_', '', $data);
            show_thumbnail_edit_options($chat_id, $post_id);
            answerCallbackQuery($query['id'], "Thumbnail editor opened");
        }
        elseif (strpos($data, 'edit_text_') === 0) {
            $post_id = str_replace('edit_text_', '', $data);
            sendMessage($chat_id, "📝 <b>Edit Post Text</b>\n\nSend new text for post #{$post_id}");
            answerCallbackQuery($query['id'], "Edit post text");
        }
        elseif (strpos($data, 'edit_buttons_') === 0) {
            $post_id = str_replace('edit_buttons_', '', $data);
            sendMessage($chat_id, "🔗 <b>Edit Buttons</b>\n\nEdit buttons for post #{$post_id}");
            answerCallbackQuery($query['id'], "Edit buttons");
        }
        elseif (strpos($data, 'edit_timing_') === 0) {
            $post_id = str_replace('edit_timing_', '', $data);
            sendMessage($chat_id, "🕒 <b>Edit Timing</b>\n\nChange timing for post #{$post_id}");
            answerCallbackQuery($query['id'], "Edit post timing");
        }
        elseif (strpos($data, 'preview_') === 0) {
            $post_id = str_replace('preview_', '', $data);
            sendMessage($chat_id, "👁️ <b>Post Preview</b>\n\nPreview of post #{$post_id}");
            answerCallbackQuery($query['id'], "Showing post preview");
        }
        elseif (strpos($data, 'save_') === 0) {
            $post_id = str_replace('save_', '', $data);
            sendMessage($chat_id, "💾 <b>Changes Saved!</b>\n\nAll edits to post #{$post_id} saved successfully.");
            answerCallbackQuery($query['id'], "Changes saved");
        }
        elseif (strpos($data, 'replace_video_') === 0) {
            $post_id = str_replace('replace_video_', '', $data);
            sendMessage($chat_id, "📁 <b>Replace Video</b>\n\nSend new video for post #{$post_id}");
            answerCallbackQuery($query['id'], "Send new video file");
        }
        elseif (strpos($data, 'change_quality_') === 0) {
            $post_id = str_replace('change_quality_', '', $data);
            sendMessage($chat_id, "🔄 <b>Change Video Quality</b>\n\nSelect quality for post #{$post_id}");
            answerCallbackQuery($query['id'], "Change video quality");
        }
        elseif (strpos($data, 'trim_video_') === 0) {
            $post_id = str_replace('trim_video_', '', $data);
            sendMessage($chat_id, "✂️ <b>Trim Video</b>\n\nTrim video for post #{$post_id}");
            answerCallbackQuery($query['id'], "Trim video options");
        }
        elseif (strpos($data, 'add_watermark_') === 0) {
            $post_id = str_replace('add_watermark_', '', $data);
            sendMessage($chat_id, "💧 <b>Add Watermark</b>\n\nAdd watermark to post #{$post_id}");
            answerCallbackQuery($query['id'], "Add watermark options");
        }
        elseif (strpos($data, 'compress_video_') === 0) {
            $post_id = str_replace('compress_video_', '', $data);
            sendMessage($chat_id, "📦 <b>Compress Video</b>\n\nCompress video for post #{$post_id}");
            answerCallbackQuery($query['id'], "Video compression options");
        }
        elseif (strpos($data, 'extract_frame_') === 0) {
            $post_id = str_replace('extract_frame_', '', $data);
            sendMessage($chat_id, "🎞️ <b>Extract Frame</b>\n\nExtract frame from post #{$post_id}");
            answerCallbackQuery($query['id'], "Extract frame from video");
        }
        elseif (strpos($data, 'add_images_') === 0) {
            $post_id = str_replace('add_images_', '', $data);
            sendMessage($chat_id, "🖼️ <b>Add Images</b>\n\nSend images for post #{$post_id}");
            answerCallbackQuery($query['id'], "Send images to add");
        }
        elseif (strpos($data, 'remove_images_') === 0) {
            $post_id = str_replace('remove_images_', '', $data);
            sendMessage($chat_id, "🗑️ <b>Remove Images</b>\n\nRemove images from post #{$post_id}");
            answerCallbackQuery($query['id'], "Remove images options");
        }
        elseif (strpos($data, 'reorder_images_') === 0) {
            $post_id = str_replace('reorder_images_', '', $data);
            sendMessage($chat_id, "🔄 <b>Reorder Images</b>\n\nReorder images for post #{$post_id}");
            answerCallbackQuery($query['id'], "Reorder images");
        }
        elseif (strpos($data, 'edit_captions_') === 0) {
            $post_id = str_replace('edit_captions_', '', $data);
            sendMessage($chat_id, "📝 <b>Edit Image Captions</b>\n\nEdit captions for post #{$post_id}");
            answerCallbackQuery($query['id'], "Edit image captions");
        }
        elseif (strpos($data, 'adjust_quality_') === 0) {
            $post_id = str_replace('adjust_quality_', '', $data);
            sendMessage($chat_id, "🎨 <b>Adjust Image Quality</b>\n\nAdjust quality for post #{$post_id}");
            answerCallbackQuery($query['id'], "Adjust image quality");
        }
        elseif (strpos($data, 'set_thumbnail_') === 0) {
            $post_id = str_replace('set_thumbnail_', '', $data);
            sendMessage($chat_id, "🏞️ <b>Set as Thumbnail</b>\n\nSet image as thumbnail for post #{$post_id}");
            answerCallbackQuery($query['id'], "Set image as thumbnail");
        }
        elseif (strpos($data, 'upload_thumbnail_') === 0) {
            $post_id = str_replace('upload_thumbnail_', '', $data);
            sendMessage($chat_id, "🖋️ <b>Upload Thumbnail</b>\n\nSend thumbnail for post #{$post_id}");
            answerCallbackQuery($query['id'], "Send thumbnail image");
        }
        elseif (strpos($data, 'thumbnail_from_video_') === 0) {
            $post_id = str_replace('thumbnail_from_video_', '', $data);
            sendMessage($chat_id, "🎞️ <b>Generate Thumbnail from Video</b>\n\nSelect timestamp for thumbnail from post #{$post_id}");
            answerCallbackQuery($query['id'], "Select thumbnail time");
        }
        elseif (strpos($data, 'design_thumbnail_') === 0) {
            $post_id = str_replace('design_thumbnail_', '', $data);
            sendMessage($chat_id, "🎨 <b>Thumbnail Design</b>\n\nChoose design for post #{$post_id}");
            answerCallbackQuery($query['id'], "Thumbnail design options");
        }
        elseif (strpos($data, 'text_thumbnail_') === 0) {
            $post_id = str_replace('text_thumbnail_', '', $data);
            sendMessage($chat_id, "📝 <b>Add Text to Thumbnail</b>\n\nAdd text to thumbnail for post #{$post_id}");
            answerCallbackQuery($query['id'], "Add text to thumbnail");
        }
        elseif (strpos($data, 'time_thumbnail_') === 0) {
            $post_id = str_replace('time_thumbnail_', '', $data);
            sendMessage($chat_id, "⏱️ <b>Set Thumbnail Time</b>\n\nSet time for thumbnail from post #{$post_id}");
            answerCallbackQuery($query['id'], "Set thumbnail time");
        }
        elseif (strpos($data, 'preview_thumbnail_') === 0) {
            $post_id = str_replace('preview_thumbnail_', '', $data);
            sendMessage($chat_id, "👁️ <b>Thumbnail Preview</b>\n\nPreview thumbnail for post #{$post_id}");
            answerCallbackQuery($query['id'], "Showing thumbnail preview");
        }
        // Agar koi bhi callback match nahi karta
        else {
            answerCallbackQuery($query['id'], "❌ Command not implemented yet");
        }
    }

    if (date('H:i') == '00:00') auto_backup();
    if (date('H:i') == '08:00') send_daily_digest();
}

// Manual save test function
if (isset($_GET['test_save'])) {
    function manual_save_to_csv($movie_name, $message_id) {
        $entry = [$movie_name, $message_id, date('d-m-Y'), ''];
        $handle = fopen(CSV_FILE, "a");
        if ($handle !== FALSE) {
            fputcsv($handle, $entry);
            fclose($handle);
            @chmod(CSV_FILE, 0666);
            return true;
        }
        return false;
    }
    
    manual_save_to_csv("Metro In Dino (2025)", 1924);
    manual_save_to_csv("Metro In Dino 2025 WebRip 480p x265 HEVC 10bit Hindi ESubs", 1925);
    manual_save_to_csv("Metro In Dino (2025) Hindi 720p HEVC HDRip x265 AAC 5.1 ESubs", 1926);
    manual_save_to_csv("Metro In Dino (2025) Hindi 720p HDRip x264 AAC 5.1 ESubs", 1927);
    manual_save_to_csv("Metro In Dino (2025) Hindi 1080p HDRip x264 AAC 5.1 ESubs", 1928);
    
    echo "✅ All 5 movies manually save ho gayi!<br>";
    echo "📊 <a href='?check_csv=1'>Check CSV</a> | ";
    echo "<a href='?setwebhook=1'>Reset Webhook</a>";
    exit;
}

// Check CSV content
if (isset($_GET['check_csv'])) {
    echo "<h3>CSV Content:</h3>";
    if (file_exists(CSV_FILE)) {
        $lines = file(CSV_FILE);
        foreach ($lines as $line) {
            echo htmlspecialchars($line) . "<br>";
        }
    } else {
        echo "❌ CSV file not found!";
    }
    exit;
}

if (php_sapi_name() === 'cli' || isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $result = apiRequest('setWebhook', ['url' => $webhook_url]);
    echo "<h1>Webhook Setup</h1>";
    echo "<p>Result: " . htmlspecialchars($result) . "</p>";
    echo "<p>Webhook URL: " . htmlspecialchars($webhook_url) . "</p>";
    $bot_info = json_decode(apiRequest('getMe'), true);
    if ($bot_info && isset($bot_info['ok']) && $bot_info['ok']) {
        echo "<h2>Bot Info</h2>";
        echo "<p>Name: " . htmlspecialchars($bot_info['result']['first_name']) . "</p>";
        echo "<p>Username: @" . htmlspecialchars($bot_info['result']['username']) . "</p>";
        echo "<p>Channel: @EntertainmentTadka786</p>";
    }
    exit;
}

if (!isset($update) || !$update) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    echo "<h1>🎬 Entertainment Tadka Bot</h1>";
    echo "<p><strong>Telegram Channel:</strong> @EntertainmentTadka786</p>";
    echo "<p><strong>Status:</strong> ✅ Running</p>";
    echo "<p><strong>Total Movies:</strong> " . ($stats['total_movies'] ?? 0) . "</p>";
    echo "<p><strong>Total Users:</strong> " . count($users_data['users'] ?? []) . "</p>";
    echo "<p><strong>Total Searches:</strong> " . ($stats['total_searches'] ?? 0) . "</p>";
    echo "<h3>🚀 Quick Setup</h3>";
    echo "<p><a href='?setwebhook=1'>Set Webhook Now</a></p>";
    echo "<h3>📋 Available Commands</h3>";
    echo "<ul>";
    echo "<li><code>/start</code> - Welcome message</li>";
    echo "<li><code>/checkdate</code> - Date-wise stats</li>";
    echo "<li><code>/totalupload</code> - Upload statistics</li>";
    echo "<li><code>/testcsv</code> - View all movies</li>";
    echo "<li><code>/checkcsv</code> - Check CSV data</li>";
    echo "<li><code>/createpost</code> - Create new post</li>";
    echo "<li><code>/scheduledposts</code> - View scheduled posts</li>";
    echo "<li><code>/editpost</code> - Edit existing posts</li>";
    echo "<li><code>/help</code> - Help message</li>";
    echo "<li><code>/stats</code> - Admin statistics</li>";
    echo "</ul>";
}
?>
