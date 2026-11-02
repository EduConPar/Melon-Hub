const { app } = require('electron');
require('/app/main.js');
app.whenReady().then(() => setTimeout(() => app.quit(), 9000));
