import { execSync, spawn } from 'child_process';
import { networkInterfaces } from 'os';

function getLocalIP() {
    const nets = networkInterfaces();
    for (const name of Object.keys(nets)) {
        for (const net of nets[name]) {
            if (net.family === 'IPv4' && !net.internal && net.address.startsWith('192.168')) {
                return net.address;
            }
        }
    }
    for (const name of Object.keys(nets)) {
        for (const net of nets[name]) {
            if (net.family === 'IPv4' && !net.internal) {
                return net.address;
            }
        }
    }
    return '127.0.0.1';
}

const ip = getLocalIP();
const port = 1420;
const devUrl = `http://${ip}:${port}`;

console.log(`\n  NativeBlade Mobile Dev`);
console.log(`  IP: ${ip}`);
console.log(`  Vite: ${devUrl}`);
console.log(`  Phone must be on the same WiFi network\n`);

const vite = spawn('npx', ['vite', '--config', 'vite.wasm.config.js'], {
    stdio: 'inherit',
    shell: true,
    env: { ...process.env }
});

setTimeout(() => {
    const tauriConfig = JSON.stringify({
        build: {
            devUrl: devUrl
        }
    });

    console.log(`\n  Starting Tauri Android Dev...\n`);

    const missing = ['ANDROID_HOME', 'NDK_HOME', 'JAVA_HOME'].filter((k) => !process.env[k]);
    if (missing.length) {
        console.error(`\n  Missing env var(s): ${missing.join(', ')}`);
        console.error('  Point them at your local Android SDK / NDK / JDK before running mobile dev:');
        console.error('    ANDROID_HOME=<path to Android/Sdk>');
        console.error('    NDK_HOME=<path to Android/Sdk/ndk/<version>>   (r28+ for 16 KB page-size)');
        console.error('    JAVA_HOME=<path to a JDK 17+>\n');
        vite.kill();
        process.exit(1);
    }

    const tauri = spawn('npx', ['tauri', 'android', 'dev', '--config', tauriConfig], {
        stdio: 'inherit',
        shell: true,
        env: { ...process.env }
    });

    tauri.on('close', () => {
        vite.kill();
        process.exit();
    });
}, 3000);

process.on('SIGINT', () => {
    vite.kill();
    process.exit();
});
