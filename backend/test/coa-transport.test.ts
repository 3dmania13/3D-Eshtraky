import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createSocket } from 'node:dgram';
import { existsSync } from 'node:fs';
import { test } from 'node:test';
import { sendCoa } from '../src/speed/live-speed-service.js';

test('real radclient accepts authenticated ACK and rejects NAK or forged ACK',
  { skip: !existsSync('/usr/bin/radclient') }, async () => {
    const socket = createSocket('udp4');
    const secret = 'local-test-secret';
    let code = 44;
    let forge = false;
    socket.on('message', (packet, remote) => {
      assert.equal(packet[0], 43); // CoA only, never Disconnect-Request (40).
      const expected = createHash('md5').update(Buffer.concat([
        packet.subarray(0, 4), Buffer.alloc(16), packet.subarray(20), Buffer.from(secret),
      ])).digest();
      assert.deepEqual(packet.subarray(4, 20), expected);
      const header = Buffer.from([code, packet[1]!, 0, 20]);
      const authenticator = createHash('md5').update(Buffer.concat([
        header, packet.subarray(4, 20), Buffer.from(forge ? 'wrong-secret' : secret),
      ])).digest();
      socket.send(Buffer.concat([header, authenticator]), remote.port, remote.address);
    });
    await new Promise<void>((resolve) => socket.bind(0, '127.0.0.1', resolve));
    const request = { host: '127.0.0.1', port: socket.address().port, secret,
      username: 'test-user', sessionId: 'test-session', address: '10.0.0.1', rate: '2M/2M' };
    try {
      assert.equal(await sendCoa(request), true);
      code = 45;
      assert.equal(await sendCoa(request), false);
      code = 44;
      forge = true;
      assert.equal(await sendCoa(request), false);
    } finally { socket.close(); }
  });
