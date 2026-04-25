import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './Tests/specs',
  timeout: 60000,
  expect: {
    timeout: 10000,
  },
  fullyParallel: false,
  // CI では複数 spec が同時に EC-CUBE プラグイン設定 (plg_ecauth_login43_config)
  // と staging EcAuth の admin (ecauth_subject UNIQUE) を共有するため、並列で
  // register / login を走らせると state が相互に上書きされ flaky になる。
  // ローカルでは並列のままでよい (個別 spec を選んで動かすケースが多いため)。
  workers: process.env.CI ? 1 : undefined,
  retries: 1,
  reporter: 'list',
  use: {
    baseURL: process.env.BASE_URL || 'https://localhost:8081',
    ignoreHTTPSErrors: true,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
});
