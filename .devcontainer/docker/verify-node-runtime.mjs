import { X509Certificate } from 'node:crypto';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { getCACertificates } from 'node:tls';

export function verifyNodeRuntime(version, certificates) {
    const match = /^(\d+)\.(\d+)\.(\d+)$/.exec(version);
    if (!match || Number(match[1]) !== 24 || Number(match[2]) < 22
        || (Number(match[2]) === 22 && Number(match[3]) < 1)) {
        throw new Error('The development image requires Node 24.22.1 or newer on the 24 line.');
    }
    if (!Array.isArray(certificates) || certificates.length === 0) {
        throw new Error('Cannot inspect the effective Node TLS roots.');
    }
    for (const certificate of certificates) {
        const subject = new X509Certificate(certificate).subject;
        if (/^CN=(AAA Certificate Services|emSign ECC Root CA - G3)$/m.test(subject)) {
            throw new Error('Blocked certificate in the effective Node TLS roots.');
        }
    }
    return certificates.length;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const count = verifyNodeRuntime(process.versions.node, getCACertificates('default'));
    console.log(`Verified Node ${process.versions.node} and ${count} effective TLS roots.`);
}
