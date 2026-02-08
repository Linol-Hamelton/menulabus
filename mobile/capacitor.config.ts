import type { CapacitorConfig } from "@capacitor/cli";

const config: CapacitorConfig = {
  appId: "pro.labus.menu",
  appName: "Labus Menu",
  webDir: "www",
  // Cheapest option: load the existing production site in the WebView.
  // If you later move to a bundled SPA, remove server.url and ship assets into webDir.
  server: {
    url: "https://menu.labus.pro/menu.php",
    cleartext: false
  }
};

export default config;
