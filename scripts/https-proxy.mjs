import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(scriptDir, '..');

const target = new URL(process.env.HARUSI_HTTPS_TARGET || 'http://127.0.0.1:8000');
const listenHost = process.env.HARUSI_HTTPS_HOST || '0.0.0.0';
const listenPort = Number.parseInt(process.env.HARUSI_HTTPS_PORT || '8443', 10);
const pfxPath = resolveProjectPath(process.env.HARUSI_HTTPS_PFX || 'storage/certs/harusi-local.pfx');
const certPath = resolveProjectPath(process.env.HARUSI_HTTPS_CERT || 'storage/certs/harusi-local.pem');
const keyPath = resolveProjectPath(process.env.HARUSI_HTTPS_KEY || 'storage/certs/harusi-local-key.pem');
const passphrase = process.env.HARUSI_HTTPS_PFX_PASSPHRASE || 'harusi-local';
const targetClient = target.protocol === 'https:' ? https : http;
const hopByHopHeaders = new Set([
    'connection',
    'keep-alive',
    'proxy-authenticate',
    'proxy-authorization',
    'te',
    'trailer',
    'trailers',
    'transfer-encoding',
    'upgrade',
]);

function resolveProjectPath(value) {
    return path.isAbsolute(value) ? value : path.join(projectRoot, value);
}

function loadTlsOptions() {
    if (fs.existsSync(pfxPath)) {
        return {
            pfx: fs.readFileSync(pfxPath),
            passphrase,
        };
    }

    if (fs.existsSync(certPath) && fs.existsSync(keyPath)) {
        return {
            cert: fs.readFileSync(certPath),
            key: fs.readFileSync(keyPath),
        };
    }

    console.error('Missing local HTTPS certificate.');
    console.error(`Expected PFX: ${pfxPath}`);
    console.error(`Or PEM pair: ${certPath} and ${keyPath}`);
    console.error('');
    console.error('Create the Windows dev certificate with:');
    console.error('  npm run https:cert -- -IpAddress 192.168.100.114');
    console.error('');
    console.error('Or create mkcert PEM files with:');
    console.error('  mkcert -key-file storage/certs/harusi-local-key.pem -cert-file storage/certs/harusi-local.pem 192.168.100.114 localhost 127.0.0.1');
    process.exit(1);
}

function proxyHeaders(request) {
    const headers = { ...request.headers };

    for (const header of Object.keys(headers)) {
        if (hopByHopHeaders.has(header.toLowerCase())) {
            delete headers[header];
        }
    }

    const publicHost = request.headers.host || `localhost:${listenPort}`;
    const forwardedFor = [request.headers['x-forwarded-for'], request.socket.remoteAddress]
        .filter(Boolean)
        .join(', ');

    headers.host = publicHost;
    headers['x-forwarded-for'] = forwardedFor;
    headers['x-forwarded-host'] = publicHost;
    headers['x-forwarded-port'] = String(listenPort);
    headers['x-forwarded-proto'] = 'https';

    return headers;
}

function rewriteLocationHeader(location, publicHost) {
    if (!location) {
        return location;
    }

    try {
        const parsed = new URL(location, target);
        const targetPort = target.port || (target.protocol === 'https:' ? '443' : '80');
        const parsedPort = parsed.port || (parsed.protocol === 'https:' ? '443' : '80');
        const isTargetLocation = parsed.hostname === target.hostname && parsedPort === targetPort;

        if (!isTargetLocation) {
            return location;
        }

        return `https://${publicHost}${parsed.pathname}${parsed.search}${parsed.hash}`;
    } catch {
        return location;
    }
}

function networkUrls() {
    const urls = [`https://localhost:${listenPort}`];

    for (const interfaces of Object.values(os.networkInterfaces())) {
        for (const info of interfaces || []) {
            if (info.family === 'IPv4' && !info.internal) {
                urls.push(`https://${info.address}:${listenPort}`);
            }
        }
    }

    return [...new Set(urls)];
}

const server = https.createServer(loadTlsOptions(), (request, response) => {
    const publicHost = request.headers.host || `localhost:${listenPort}`;
    const options = {
        protocol: target.protocol,
        hostname: target.hostname,
        port: target.port || (target.protocol === 'https:' ? 443 : 80),
        method: request.method,
        path: request.url,
        headers: proxyHeaders(request),
    };

    const proxyRequest = targetClient.request(options, (proxyResponse) => {
        const headers = { ...proxyResponse.headers };

        if (headers.location) {
            headers.location = rewriteLocationHeader(headers.location, publicHost);
        }

        response.writeHead(proxyResponse.statusCode || 502, headers);
        proxyResponse.pipe(response);
    });

    proxyRequest.on('error', (error) => {
        response.writeHead(502, { 'content-type': 'text/plain; charset=utf-8' });
        response.end(`HTTPS proxy could not reach ${target.href}\n\n${error.message}\n`);
    });

    request.pipe(proxyRequest);
});

server.listen(listenPort, listenHost, () => {
    console.log(`Forwarding HTTPS traffic to ${target.href}`);
    console.log('Open one of these URLs:');

    for (const url of networkUrls()) {
        console.log(`  ${url}/scanner/scan`);
    }
});
