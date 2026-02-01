/**
 * EcAuth WebAuthn ヘルパー
 */
var EcAuthWebAuthn = (function() {
    'use strict';

    /**
     * ArrayBuffer を Base64URL 文字列にエンコードする。
     */
    function base64UrlEncode(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary)
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=+$/, '');
    }

    /**
     * Base64URL 文字列を ArrayBuffer にデコードする。
     */
    function base64UrlDecode(str) {
        var base64 = str.replace(/-/g, '+').replace(/_/g, '/');
        var padding = base64.length % 4;
        if (padding) {
            base64 += '===='.substring(padding);
        }
        var binary = atob(base64);
        var buffer = new ArrayBuffer(binary.length);
        var bytes = new Uint8Array(buffer);
        for (var i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return buffer;
    }

    /**
     * パスキー認証を実行する。
     *
     * @param {string} optionsUrl authenticate/options エンドポイント
     * @param {string} verifyUrl authenticate/verify エンドポイント
     * @param {string} csrfToken CSRF トークン
     * @returns {Promise<Object>} 認証結果（redirect_url を含む）
     */
    function authenticate(optionsUrl, verifyUrl, csrfToken) {
        // 1. 認証オプション取得
        return fetch(optionsUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({})
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Failed to get authentication options: ' + response.status);
            }
            return response.json();
        })
        .then(function(options) {
            // 2. WebAuthn API 用にデータ変換
            var publicKeyOptions = {
                challenge: base64UrlDecode(options.challenge),
                rpId: options.rpId,
                userVerification: options.userVerification || 'preferred',
                timeout: options.timeout || 60000
            };

            if (options.allowCredentials && options.allowCredentials.length > 0) {
                publicKeyOptions.allowCredentials = options.allowCredentials.map(function(cred) {
                    return {
                        id: base64UrlDecode(cred.id),
                        type: cred.type,
                        transports: cred.transports || []
                    };
                });
            }

            // 3. ブラウザ認証ダイアログ表示
            return navigator.credentials.get({ publicKey: publicKeyOptions });
        })
        .then(function(assertion) {
            // 4. 認証結果をサーバーに送信
            var assertionData = {
                response: {
                    id: assertion.id,
                    rawId: base64UrlEncode(assertion.rawId),
                    response: {
                        authenticatorData: base64UrlEncode(assertion.response.authenticatorData),
                        clientDataJSON: base64UrlEncode(assertion.response.clientDataJSON),
                        signature: base64UrlEncode(assertion.response.signature)
                    },
                    type: assertion.type
                }
            };

            if (assertion.response.userHandle) {
                assertionData.response.response.userHandle = base64UrlEncode(assertion.response.userHandle);
            }

            return fetch(verifyUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify(assertionData)
            });
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Authentication verification failed: ' + response.status);
            }
            return response.json();
        });
    }

    /**
     * パスキー登録を実行する。
     *
     * @param {string} optionsUrl register/options エンドポイント
     * @param {string} verifyUrl register/verify エンドポイント
     * @param {string} csrfToken CSRF トークン
     * @param {string} b2bSubject B2B ユーザー Subject
     * @param {string|null} deviceName デバイス名
     * @returns {Promise<Object>} 登録結果
     */
    function register(optionsUrl, verifyUrl, csrfToken, b2bSubject, deviceName) {
        // 1. 登録オプション取得
        var requestBody = { b2b_subject: b2bSubject };
        if (deviceName) {
            requestBody.device_name = deviceName;
        }

        return fetch(optionsUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify(requestBody)
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Failed to get registration options: ' + response.status);
            }
            return response.json();
        })
        .then(function(options) {
            // 2. WebAuthn API 用にデータ変換
            var publicKeyOptions = {
                challenge: base64UrlDecode(options.challenge),
                rp: {
                    id: options.rp.id,
                    name: options.rp.name
                },
                user: {
                    id: base64UrlDecode(options.user.id),
                    name: options.user.name,
                    displayName: options.user.displayName
                },
                pubKeyCredParams: options.pubKeyCredParams,
                authenticatorSelection: options.authenticatorSelection || {},
                timeout: options.timeout || 60000,
                attestation: options.attestation || 'none'
            };

            if (options.excludeCredentials && options.excludeCredentials.length > 0) {
                publicKeyOptions.excludeCredentials = options.excludeCredentials.map(function(cred) {
                    return {
                        id: base64UrlDecode(cred.id),
                        type: cred.type,
                        transports: cred.transports || []
                    };
                });
            }

            // 3. ブラウザ登録ダイアログ表示
            return navigator.credentials.create({ publicKey: publicKeyOptions });
        })
        .then(function(credential) {
            // 4. 登録結果をサーバーに送信
            var credentialData = {
                response: {
                    id: credential.id,
                    rawId: base64UrlEncode(credential.rawId),
                    response: {
                        attestationObject: base64UrlEncode(credential.response.attestationObject),
                        clientDataJSON: base64UrlEncode(credential.response.clientDataJSON)
                    },
                    type: credential.type
                }
            };

            if (deviceName) {
                credentialData.device_name = deviceName;
            }

            return fetch(verifyUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify(credentialData)
            });
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Registration verification failed: ' + response.status);
            }
            return response.json();
        });
    }

    return {
        authenticate: authenticate,
        register: register,
        base64UrlEncode: base64UrlEncode,
        base64UrlDecode: base64UrlDecode
    };
})();
