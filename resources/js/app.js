// Hidden alerts are remembered for this browser tab only, so they come back in a new session.
window.isAlertDismissed = (key) => {
    try {
        return sessionStorage.getItem(`alert:${key}`) === '1';
    } catch (error) {
        return false;
    }
};

window.dismissAlert = (key) => {
    try {
        sessionStorage.setItem(`alert:${key}`, '1');
    } catch (error) {
        // Storage can be blocked; the alert stays hidden until the page is left.
    }
};
