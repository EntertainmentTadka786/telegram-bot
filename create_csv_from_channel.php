<?php
define('BOT_TOKEN', '8315381064:AAGk0FGVGmB8j5SjpBvW3rD3_kQHe_hyOWU');
define('CHANNEL_ID', '@EntertainmentTadka786');
define('CSV_FILE', 'movies.csv');

function getChannelHistory() {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getChatHistory?chat_id=" . CHANNEL_ID . "&limit=100";
    $response = file_get_contents($url);
    return json_decode($response, true);
}

function appendToCSV($movie_name, $message_id, $date) {
    $handle = fopen(CSV_FILE, "a");
    fputcsv($handle, [$movie_name, $message_id, $date]);
    fclose($handle);
}

echo "<h2>📥 Fetching videos from channel...</h2>";

// Create CSV file with header if not exists
if (!file_exists(CSV_FILE)) {
    $handle = fopen(CSV_FILE, "w");
    fputcsv($handle, ['movie_name', 'message_id', 'date']);
    fclose($handle);
    echo "<p>✅ New CSV file created!</p>";
}

$history = getChannelHistory();
$count = 0;

if (isset($history['result']['messages'])) {
    foreach ($history['result']['messages'] as $message) {
        $message_id = $message['message_id'];
        $text = isset($message['text']) ? $message['text'] : (isset($message['caption']) ? $message['caption'] : '');
        $date = date('d-m-Y', $message['date']);
        
        if (!empty(trim($text))) {
            appendToCSV($text, $message_id, $date);
            $count++;
            echo "<p>✅ Added: " . htmlspecialchars($text) . " (ID: $message_id)</p>";
        }
    }
    
    echo "<h2>🎉 $count movies added to CSV successfully!</h2>";
    echo "<p><a href='movies.csv'>Download CSV File</a></p>";
} else {
    echo "<h2>❌ Error: Could not fetch channel history</h2>";
    echo "<p>Check:</p>";
    echo "<ul>";
    echo "<li>Bot token is correct</li>";
    echo "<li>Bot is admin in your channel</li>";
    echo "<li>Channel username is correct</li>";
    echo "</ul>";
    echo "<pre>" . print_r($history, true) . "</pre>";
}
?>
