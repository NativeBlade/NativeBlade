import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { execute, __setInvokeForTests, __resetForTests } from '../../../js/runtime/db-bridge.js';

describe('db-bridge', () => {
    beforeEach(() => __resetForTests());

    it('runs the query through the db_query command with the message fields', async () => {
        const calls = [];
        __setInvokeForTests(async (command, args) => { calls.push([command, args]); return [{ id: 1 }]; });

        const result = await execute({
            nativeblade: 'db',
            type: 'select',
            sql: 'select * from t where id = ?',
            bindings: [1],
            driver: 'mysql',
            connection: 'mysql://u:p@h:3306/d',
        });

        assert.deepEqual(result, [{ id: 1 }]);
        assert.deepEqual(calls, [['db_query', {
            driver: 'mysql',
            connection: 'mysql://u:p@h:3306/d',
            queryType: 'select',
            sql: 'select * from t where id = ?',
            bindings: [1],
        }]]);
    });

    it('a rejected invoke propagates so the bridge answers with the error', async () => {
        __setInvokeForTests(async () => { throw new Error('no such table: t'); });

        await assert.rejects(() => execute({ type: 'select', sql: 'select 1' }), /no such table: t/);
    });

    it('refuses outside Tauri instead of importing the host API', async () => {
        await assert.rejects(() => execute({ type: 'select', sql: 'select 1' }), /only available inside the app/);
    });
});
