<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bot Token & Channel
define('BOT_TOKEN', '8315381064:AAGk0FGVGmB8j5SjpBvW3rD3_kQHe_hyOWU');
define('CHANNEL_ID', '@EntertainmentTadka786');
define('GROUP_CHANNEL_ID', '@EntertainmentTadka7860');
define('CSV_FILE', 'movies.csv');
define('USERS_FILE', 'users.json');
define('STATS_FILE', 'bot_stats.json');
define('BACKUP_DIR', 'backups/');
define('CACHE_EXPIRY', 300);

// Ensure files exist with proper permissions
if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode(['users' => [], 'total_requests' => 0]));
    chmod(USERS_FILE, 0666);
}

if (!file_exists(CSV_FILE)) {
    file_put_contents(CSV_FILE, "movie_name,message_id,date\n");
    chmod(CSV_FILE, 0666);
}

if (!file_exists(STATS_FILE)) {
    file_put_contents(STATS_FILE, json_encode([
        'total_movies' => 0,
        'total_users' => 0,
        'total_searches' => 0,
        'last_updated' => date('Y-m-d H:i:s')
    ]));
    chmod(STATS_FILE, 0666);
}

if (!file_exists(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0777, true);
}

// Movie messages array
$movie_messages = array();
$movie_cache = array();
$waiting_users = array();

// ==============================
// NEW: Stats management functions
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
// NEW: Smart caching system
// ==============================
function get_cached_movies() {
    global $movie_cache;
    
    if (!empty($movie_cache) && (time() - $movie_cache['timestamp']) < CACHE_EXPIRY) {
        return $movie_cache['data'];
    }
    
    // Refresh cache
    $movie_cache = [
        'data' => load_and_clean_csv(),
        'timestamp' => time()
    ];
    
    return $movie_cache['data'];
}

// ==============================
// NEW: AI-powered smart search
// ==============================
function smart_search($query) {
    global $movie_messages;
    
    $query_lower = strtolower(trim($query));
    $results = array();
    
    // MULTILEVEL SEARCH ALGORITHM
    foreach ($movie_messages as $movie => $msg_ids) {
        $score = 0;
        
        // EXACT MATCH - Highest priority
        if ($movie == $query_lower) {
            $score = 100;
        }
        // PARTIAL MATCH - Medium priority
        elseif (strpos($movie, $query_lower) !== false) {
            $score = 80 - (strlen($movie) - strlen($query_lower));
        }
        // SIMILARITY MATCH - Fuzzy search
        else {
            similar_text($movie, $query_lower, $similarity);
            if ($similarity > 60) {
                $score = $similarity;
            }
        }
        
        if ($score > 0) {
            $results[$movie] = [
                'score' => $score,
                'count' => count($msg_ids)
            ];
        }
    }
    
    // SORT BY RELEVANCE SCORE
    uasort($results, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    
    return array_slice($results, 0, 10);
}

// ==============================
// NEW: Multi-language support
// ==============================
function detect_language($text) {
    $hindi_keywords = ['फिल्म', 'मूवी', 'डाउनलोड', 'हिंदी'];
    $english_keywords = ['movie', 'download', 'watch', 'print'];
    
    $hindi_count = 0;
    $english_count = 0;
    
    foreach ($hindi_keywords as $keyword) {
        if (strpos($text, $keyword) !== false) $hindi_count++;
    }
    
    foreach ($english_keywords as $keyword) {
        if (stripos($text, $keyword) !== false) $english_count++;
    }
    
    return $hindi_count > $english_count ? 'hindi' : 'english';
}

function send_multilingual_response($chat_id, $message_type, $language) {
    $responses = [
        'hindi' => [
            'welcome' => "🎬 स्वागत है! कौन सी मूवी चाहिए?",
            'found' => "✅ मूवी मिल गई!",
            'not_found' => "❌ अभी यह मूवी उपलब्ध नहीं है",
            'searching' => "🔍 आपकी मूवी ढूंढ रहे हैं..."
        ],
        'english' => [
            'welcome' => "🎬 Welcome! Which movie do you want?",
            'found' => "✅ Movie found!",
            'not_found' => "❌ Movie not available yet",
            'searching' => "🔍 Searching for your movie..."
        ]
    ];
    
    sendMessage($chat_id, $responses[$language][$message_type]);
}

// ==============================
// NEW: Auto-backup system
// ==============================
function auto_backup() {
    $backup_files = [CSV_FILE, USERS_FILE, STATS_FILE];
    $backup_dir = BACKUP_DIR . date('Y-m-d');
    
    if (!file_exists($backup_dir)) {
        mkdir($backup_dir, 0777, true);
    }
    
    foreach ($backup_files as $file) {
        if (file_exists($file)) {
            copy($file, $backup_dir . '/' . basename($file) . '.bak');
        }
    }
    
    // Keep only last 7 backups
    $old_backups = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    if (count($old_backups) > 7) {
        array_map('rmdir', array_slice($old_backups, 0, -7));
    }
}

// ==============================
// NEW: Daily digest feature
// ==============================
function send_daily_digest() {
    $yesterday = date('d-m-Y', strtotime('-1 day'));
    $yesterday_movies = array();
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3 && $row[2] == $yesterday) {
                $yesterday_movies[] = $row[0];
            }
        }
        fclose($handle);
    }
    
    if (!empty($yesterday_movies)) {
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        foreach ($users_data['users'] as $user_id => $user_data) {
            $msg = "📅 <b>Daily Movie Digest</b>\n\n";
            $msg .= "📢 Join our channel: @EntertainmentTadka786\n\n";
            $msg .= "🎬 Yesterday's Uploads (" . $yesterday . "):\n";
            
            foreach (array_slice($yesterday_movies, 0, 10) as $movie) {
                $msg .= "• " . $movie . "\n";
            }
            
            if (count($yesterday_movies) > 10) {
                $msg .= "• ... and " . (count($yesterday_movies) - 10) . " more\n";
            }
            
            $msg .= "\n🔥 Total: " . count($yesterday_movies) . " movies";
            sendMessage($user_id, $msg, null, 'HTML');
        }
    }
}

// ==============================
// NEW: User points system
// ==============================
function update_user_points($user_id, $action) {
    $points_map = [
        'search' => 1,
        'found_movie' => 5,
        'daily_login' => 10
    ];
    
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    if (!isset($users_data['users'][$user_id]['points'])) {
        $users_data['users'][$user_id]['points'] = 0;
    }
    
    $users_data['users'][$user_id]['points'] += $points_map[$action];
    $users_data['users'][$user_id]['last_activity'] = date('Y-m-d H:i:s');
    
    file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
}

// ==============================
// CSV functions
// ==============================
function load_and_clean_csv($filename = CSV_FILE) {
    global $movie_messages;
    
    if (!file_exists($filename)) {
        file_put_contents($filename, "movie_name,message_id,date\n");
        return array();
    }
    
    $data = array();
    $handle = fopen($filename, "r");
    if ($handle !== FALSE) {
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3 && is_numeric($row[1]) && !empty(trim($row[0]))) {
                $data[] = array(
                    'movie_name' => trim($row[0]),
                    'message_id' => intval($row[1]),
                    'date' => $row[2]
                );
                
                $movie = strtolower(trim($row[0]));
                if (!isset($movie_messages[$movie])) {
                    $movie_messages[$movie] = array();
                }
                $movie_messages[$movie][] = intval($row[1]);
            }
        }
        fclose($handle);
    }
    
    // Update stats
    update_stats('total_movies', 0);
    update_stats('total_movies', count($data));
    
    // Cleaned data ko save karo
    $handle = fopen($filename, "w");
    fputcsv($handle, array('movie_name', 'message_id', 'date'));
    foreach ($data as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);
    
    error_log("✅ CSV loaded and cleaned - " . count($data) . " movies");
    return $data;
}

function append_movie($movie_name, $message_id) {
    if (empty(trim($movie_name))) {
        error_log("❌ Empty movie_name skipped");
        return;
    }
    
    if (!is_numeric($message_id)) {
        error_log("❌ Non-numeric message_id skipped: " . $message_id);
        return;
    }
    
    $date = date('d-m-Y');
    $data = array(
        'movie_name' => trim($movie_name),
        'message_id' => intval($message_id),
        'date' => $date
    );
    
    $handle = fopen(CSV_FILE, "a");
    fputcsv($handle, $data);
    fclose($handle);
    
    // Memory mein bhi update karo
    global $movie_messages;
    $movie = strtolower(trim($movie_name));
    if (!isset($movie_messages[$movie])) {
        $movie_messages[$movie] = array();
    }
    $movie_messages[$movie][] = intval($message_id);
    
    // NEW: Notify waiting users
    global $waiting_users;
    foreach ($waiting_users as $query => $users) {
        if (strpos($movie, $query) !== false) {
            foreach ($users as $user_data) {
                list($user_chat_id, $user_id) = $user_data;
                forwardMessage($user_chat_id, CHANNEL_ID, $message_id);
                sendMessage($user_chat_id, "✅ '$query' ab channel me add ho gaya!");
            }
            unset($waiting_users[$query]);
        }
    }
    
    error_log("🎬 '" . $movie_name . "' saved to CSV (Message ID: " . $message_id . ")");
    update_stats('total_movies', 1);
}

// ==============================
// NEW: Advanced search function
// ==============================
function advanced_search($chat_id, $query) {
    global $movie_messages, $waiting_users;
    
    $query_lower = strtolower(trim($query));
    if (strlen($query_lower) < 2) {
        sendMessage($chat_id, "❌ Please enter at least 2 characters for search");
        return;
    }
    
    // Use AI-powered search
    $found_movies = smart_search($query_lower);
    
    if (!empty($found_movies)) {
        $msg = "🔍 Found " . count($found_movies) . " movies for '$query':\n\n";
        $count = 1;
        foreach ($found_movies as $movie => $data) {
            $msg .= "$count. $movie (" . $data['count'] . " messages)\n";
            $count++;
            if ($count > 15) break;
        }
        
        sendMessage($chat_id, $msg);
        
        // Show buttons for top matches
        $top_matches = array_slice(array_keys($found_movies), 0, 5);
        $keyboard = array('inline_keyboard' => array());
        foreach ($top_matches as $movie) {
            $keyboard['inline_keyboard'][] = array(
                array('text' => "🎬 " . ucwords($movie), 'callback_data' => $movie)
            );
        }
        
        sendMessage($chat_id, "🚀 Top matches:", $keyboard);
        
        update_user_points($chat_id, 'found_movie');
        
    } else {
        $language = detect_language($query);
        send_multilingual_response($chat_id, 'not_found', $language);
        
        // Add to waiting list
        if (!isset($waiting_users[$query_lower])) {
            $waiting_users[$query_lower] = array();
        }
        $waiting_users[$query_lower][] = array($chat_id, $user_id);
    }
    
    update_stats('total_searches', 1);
    update_user_points($chat_id, 'search');
}

// ==============================
// NEW: Admin commands
// ==============================
function admin_stats($chat_id) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    
    $msg = "📊 <b>Bot Statistics</b>\n\n";
    $msg .= "🎬 Total Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $msg .= "👥 Total Users: " . $total_users . "\n";
    $msg .= "🔍 Total Searches: " . ($stats['total_seaches'] ?? 0) . "\n";
    $msg .= "🕒 Last Updated: " . ($stats['last_updated'] ?? 'N/A') . "\n\n";
    
    // Recent activity
    $csv_data = load_and_clean_csv();
    $recent_movies = array_slice($csv_data, -5);
    $msg .= "📈 <b>Recent Uploads:</b>\n";
    foreach ($recent_movies as $movie) {
        $msg .= "• " . $movie['movie_name'] . " (" . $movie['date'] . ")\n";
    }
    
    sendMessage($chat_id, $msg, null, 'HTML');
}

// ==============================
// Telegram API functions
// ==============================
function apiRequest($method, $params = array()) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $options = array(
        'http' => array(
            'method' => 'POST',
            'content' => http_build_query($params),
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
        )
    );
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    return $result;
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null) {
    $data = array(
        'chat_id' => $chat_id,
        'text' => $text
    );
    
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    
    if ($parse_mode) {
        $data['parse_mode'] = $parse_mode;
    }
    
    apiRequest('sendMessage', $data);
}

function forwardMessage($chat_id, $from_chat_id, $message_id) {
    apiRequest('forwardMessage', array(
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ));
}

function answerCallbackQuery($callback_query_id, $text = null) {
    $data = array('callback_query_id' => $callback_query_id);
    if ($text) {
        $data['text'] = $text;
    }
    apiRequest('answerCallbackQuery', $data);
}

// ==============================
// Command functions
// ==============================
function check_date($chat_id) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "⚠️ Abhi tak koi data save nahi hua.");
        return;
    }
    
    $date_counts = array();
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3) {
                $date = $row[2];
                if (!isset($date_counts[$date])) {
                    $date_counts[$date] = 0;
                }
                $date_counts[$date]++;
            }
        }
        fclose($handle);
    }
    
    krsort($date_counts);
    
    $msg = "📅 <b>Movies Upload Record</b>\n\n";
    $total_days = 0;
    $total_movies = 0;
    
    foreach ($date_counts as $date => $count) {
        $msg .= "➡️ $date: $count movies\n";
        $total_days++;
        $total_movies += $count;
    }
    
    $msg .= "\n📊 <b>Summary:</b>\n";
    $msg .= "• Total Days: $total_days\n";
    $msg .= "• Total Movies: $total_movies\n";
    $msg .= "• Average per day: " . round($total_movies / max(1, $total_days), 2);
    
    sendMessage($chat_id, $msg, null, 'HTML');
}

function total_uploads($chat_id, $page = 1) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "⚠️ Abhi tak koi data save nahi hua.");
        return;
    }
    
    $items_per_page = 5;
    $total = 0;
    $today_str = date('d-m-Y');
    $yesterday_str = date('d-m-Y', strtotime('-1 day'));
    $today_count = 0;
    $yesterday_count = 0;
    $weekly_total = 0;
    $all_movies = array();
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle); // Skip header
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3) {
                $total++;
                $movie_name = $row[0];
                $date = $row[2];
                
                $all_movies[] = [
                    'name' => $movie_name,
                    'date' => $date,
                    'message_id' => $row[1]
                ];
                
                if ($date == $today_str) {
                    $today_count++;
                } elseif ($date == $yesterday_str) {
                    $yesterday_count++;
                }
                
                // Weekly count (last 7 days)
                $movie_date = DateTime::createFromFormat('d-m-Y', $date);
                if ($movie_date) {
                    $diff = $movie_date->diff(new DateTime());
                    if ($diff->days <= 7) {
                        $weekly_total++;
                    }
                }
            }
        }
        fclose($handle);
    }
    
    // Reverse array to show latest first
    $all_movies = array_reverse($all_movies);
    
    // Calculate pagination
    $total_pages = ceil(count($all_movies) / $items_per_page);
    $current_page = max(1, min($page, $total_pages));
    $start_index = ($current_page - 1) * $items_per_page;
    $paginated_movies = array_slice($all_movies, $start_index, $items_per_page);
    
    $msg = "📊 <b>Upload Statistics</b>\n\n";
    $msg .= "• 🎬 Total: $total movies\n";
    $msg .= "• 🚀 Today: $today_count movies\n";
    $msg .= "• 📈 Yesterday: $yesterday_count movies\n";
    $msg .= "• 📅 Last 7 days: $weekly_total movies\n";
    $msg .= "• ⭐ Daily avg: " . round($total / max(1, count(array_unique(array_column($all_movies, 'date')))), 2) . " movies\n\n";
    
    $msg .= "🎬 <b>Movies List (Page $current_page/$total_pages):</b>\n\n";
    
    $index = 1;
    foreach ($paginated_movies as $movie) {
        $msg .= "<b>" . ($start_index + $index) . ".</b> " . $movie['name'] . "\n";
        $msg .= "   📅: " . $movie['date'] . " | ID: " . $movie['message_id'] . "\n\n";
        $index++;
    }
    
    // Add pagination buttons if needed
    $keyboard = null;
    if ($total_pages > 1) {
        $keyboard = ['inline_keyboard' => []];
        
        $row_buttons = [];
        if ($current_page > 1) {
            $row_buttons[] = ['text' => '⏮️ Previous', 'callback_data' => 'uploads_page_' . ($current_page - 1)];
        }
        
        if ($current_page < $total_pages) {
            $row_buttons[] = ['text' => '⏭️ Next', 'callback_data' => 'uploads_page_' . ($current_page + 1)];
        }
        
        if (!empty($row_buttons)) {
            $keyboard['inline_keyboard'][] = $row_buttons;
        }
        
        $keyboard['inline_keyboard'][] = [
            ['text' => '🎬 View Movie', 'callback_data' => 'view_current_movie'],
            ['text' => '🛑 Stop', 'callback_data' => 'uploads_stop']
        ];
    }
    
    sendMessage($chat_id, $msg, $keyboard, 'HTML');
}

function test_csv($chat_id) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "⚠️ CSV file not found.");
        return;
    }
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle);
        $index = 1;
        $msg = "";
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3) {
                $line = "$index. {$row[0]} | ID: {$row[1]} | Date: {$row[2]}\n";
                if (strlen($msg) + strlen($line) > 4000) {
                    sendMessage($chat_id, $msg);
                    $msg = "";
                }
                $msg .= $line;
                $index++;
            }
        }
        fclose($handle);
        
        if (!empty($msg)) {
            sendMessage($chat_id, $msg);
        }
    }
}

// ==============================
// Main update processing
// ==============================
$update = json_decode(file_get_contents('php://input'), true);
if ($update) {
    // Pehle CSV data ko load karo
    get_cached_movies();
    
    // Check karo agar channel ka message hai
    if (isset($update['channel_post'])) {
        $message = $update['channel_post'];
        $message_id = $message['message_id'];
        $text = isset($message['text']) ? $message['text'] : (isset($message['caption']) ? $message['caption'] : '');
        
        if (!empty(trim($text))) {
            append_movie($text, $message_id);
        }
    }
    
    // Check karo agar user ka message hai
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = isset($message['text']) ? $message['text'] : '';
        
        // Users.json mein user ko add/save karo
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
        
        // Update last active
        $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
        file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
        
        // Commands handle karo
        if (strpos($text, '/') === 0) {
            $command = explode(' ', $text)[0];
            
            if ($command == '/checkdate') {
                check_date($chat_id);
            } 
            elseif ($command == '/totaluploads') {
                total_uploads($chat_id);
            }
            elseif ($command == '/testcsv') {
                test_csv($chat_id);
            }
            elseif ($command == '/start') {
                $welcome_msg = "🎬 <b>Welcome to Entertainment Tadka!</b>\n\n";
                $welcome_msg .= "📢 Join our channel: @EntertainmentTadka786\n\n";
                $welcome_msg .= "🤖 <b>Bot Commands:</b>\n";
                $welcome_msg .= "/start - Welcome message\n";
                $welcome_msg .= "/checkdate - Date-wise upload stats\n";
                $welcome_msg .= "/totaluploads - Total upload counts\n";
                $welcome_msg .= "/help - Help message\n\n";
                $welcome_msg .= "🔍 <b>Simply type any movie name to search!</b>";
                
                sendMessage($chat_id, $welcome_msg, null, 'HTML');
                update_user_points($chat_id, 'daily_login');
            }
            elseif ($command == '/stats' && $user_id == 1080317415) { // ADMIN ID CHANGE KARNA
                admin_stats($chat_id);
            }
            elseif ($command == '/help') {
                $help_msg = "🤖 <b>Entertainment Tadka Bot</b>\n\n";
                $help_msg .= "📢 Join our channel: @EntertainmentTadka786\n\n";
                $help_msg .= "📋 <b>Available Commands:</b>\n\n";
                $help_msg .= "/start - Welcome message\n";
                $help_msg .= "/checkdate - Date-wise upload stats\n";
                $help_msg .= "/totaluploads - Total upload counts\n";
                $help_msg .= "/testcsv - View all movies\n";
                $help_msg .= "/help - This help message\n\n";
                $help_msg .= "🔍 <b>Simply type any movie name to search!</b>";
                
                sendMessage($chat_id, $help_msg, null, 'HTML');
            }
        } 
        // Text messages ko handle karo (NEW: Advanced search)
        else if (!empty(trim($text))) {
            $language = detect_language($text);
            send_multilingual_response($chat_id, 'searching', $language);
            advanced_search($chat_id, $text);
        }
    }
    
    // Button clicks (callback queries) handle karo
    if (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $message = $query['message'];
        $chat_id = $message['chat']['id'];
        $data = $query['data'];
        
        // Movie selection handle karo
        global $movie_messages;
        $movie_lower = strtolower($data);
        if (isset($movie_messages[$movie_lower])) {
            $message_count = count($movie_messages[$movie_lower]);
            foreach ($movie_messages[$movie_lower] as $msg_id) {
                forwardMessage($chat_id, CHANNEL_ID, $msg_id);
            }
            
            $forward_msg = "✅ '$data' ke $message_count messages forward ho gaye!\n\n";
            $forward_msg .= "📢 Join our channel: @EntertainmentTadka786";
            
            sendMessage($chat_id, $forward_msg);
            answerCallbackQuery($query['id'], "🎬 $message_count messages forwarded!");
        } 
        // Uploads pagination handle karo
        elseif (strpos($data, 'uploads_page_') === 0) {
            $page = intval(str_replace('uploads_page_', '', $data));
            total_uploads($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page loaded");
        }
        elseif ($data == 'view_current_movie') {
            // Current page ki movies show karein
            $message_text = $query['message']['text'];
            if (preg_match('/Page (\d+)\/(\d+)/', $message_text, $matches)) {
                $current_page = $matches[1];
                total_uploads($chat_id, $current_page);
            }
            answerCallbackQuery($query['id'], "Loading movies...");
        }
        elseif ($data == 'uploads_stop') {
            sendMessage($chat_id, "✅ Pagination stopped. Type /totaluploads again to restart.");
            answerCallbackQuery($query['id'], "Pagination stopped");
        }
        else {
            sendMessage($chat_id, "❌ Movie not found: " . $data);
            answerCallbackQuery($query['id'], "❌ Movie not available");
        }
    }
    
    // Auto-backup daily at midnight
    if (date('H:i') == '00:00') {
        auto_backup();
    }
    
    // Daily digest at 8 AM
    if (date('H:i') == '08:00') {
        send_daily_digest();
    }
}

// Webhook setup
if (php_sapi_name() === 'cli' || isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . 
                   "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    
    $result = apiRequest('setWebhook', array('url' => $webhook_url));
    echo "<h1>Webhook Setup</h1>";
    echo "<p>Result: " . $result . "</p>";
    echo "<p>Webhook URL: " . $webhook_url . "</p>";
    
    // Show bot info
    $bot_info = json_decode(apiRequest('getMe'), true);
    if ($bot_info['ok']) {
        echo "<h2>Bot Info</h2>";
        echo "<p>Name: " . $bot_info['result']['first_name'] . "</p>";
        echo "<p>Username: @" . $bot_info['result']['username'] . "</p>";
        echo "<p>Channel: @EntertainmentTadka786</p>";
    }
    exit;
}

// Agar koi update nahi aaya toh info page dikhao
if (!$update) {
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
    echo "<li><code>/totaluploads</code> - Upload statistics</li>";
    echo "<li><code>/testcsv</code> - View all movies</li>";
    echo "<li><code>/help</code> - Help message</li>";
    echo "<li><code>/stats</code> - Admin statistics</li>";
    echo "</ul>";
    
    echo "<h3>📊 File Status</h3>";
    echo "<ul>";
    echo "<li>CSV File: " . (is_writable(CSV_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "<li>Users File: " . (is_writable(USERS_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "<li>Stats File: " . (is_writable(STATS_FILE) ? "✅ Writable" : "❌ Not Writable") . "</li>";
    echo "</ul>";
    
    echo "<h3>🌟 Special Features</h3>";
    echo "<ul>";
    echo "<li>🤖 AI-Powered Search</li>";
    echo "<li>🔔 Smart Notifications</li>";
    echo "<li>📊 Advanced Analytics</li>";
    echo "<li>🌐 Multi-Language Support</li>";
    echo "<li>⚡ Smart Caching</li>";
    echo "<li>🛡️ Auto-Backup System</li>";
    echo "<li>🎮 User Points System</li>";
    echo "<li>📅 Daily Digest</li>";
    echo "</ul>";
}
