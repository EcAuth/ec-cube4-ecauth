import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './Tests/specs',
  timeout: 60000,
  expect: {
    timeout: 10000,
  },
  fullyParallel: false,
  retries: 1,
  reporter: 'list',
  use: {
    baseURL: process.env.BASE_URL || 'https://localhost:8081',
    ignoreHTTPSErrors: true,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
});
