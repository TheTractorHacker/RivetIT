// Keep PHP sessions alive
// Sends requests to keepalive.php in the background every 8 mins to prevent PHP garbage collection ending sessions

function keep_alive() {

    //Send a GET request to keepalive.php as keepalive.php?keepalive
    jQuery.get(
        "/keepalive.php",
        {keepalive: 'true'},
        function(data) {
            // Don't care about a response
        }
    );

}

// Run every 8 mins
setInterval(keep_alive, 480000);

// Fire immediately when internet reconnects so sessions survive outages
window.addEventListener('online', keep_alive);

// Fire when the tab becomes visible again (handles backgrounded tabs and returning from other apps)
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        keep_alive();
    }
});
