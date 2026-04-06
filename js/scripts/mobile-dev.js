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

    const tauri = spawn('npx', ['tauri', 'android', 'dev', '--config', tauriConfig], {
        stdio: 'inherit',
        shell: true,
        env: {
            ...process.env,
            ANDROID_HOME: process.env.ANDROID_HOME || 'C:\\Users\\siste\\AppData\\Local\\Android\\Sdk',
            NDK_HOME: process.env.NDK_HOME || 'C:\\Users\\siste\\AppData\\Local\\Android\\Sdk\\ndk\\30.0.14904198',
            JAVA_HOME: process.env.JAVA_HOME || 'C:\\grandle\\jdk-21.0.9',
        }
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
