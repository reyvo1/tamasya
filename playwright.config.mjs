import {defineConfig} from '@playwright/test';

export default defineConfig({
  testDir: './tests/uat_rc1/browser',
  timeout: 90000,
  expect: {timeout: 15000},
  workers: 1,
  retries: 0,
  reporter: [['list'], ['json', {outputFile: 'tests/uat_rc1/logs/browser-results.json'}]],
  outputDir: 'tests/uat_rc1/logs/playwright',
  use: {
    baseURL: process.env.TAMASYA_UAT_BASE_URL || 'http://127.0.0.1:38184',
    browserName: 'chromium',
    timezoneId: 'Asia/Makassar',
    trace: 'retain-on-failure',
    video: 'off',
    screenshot: 'only-on-failure',
    serviceWorkers: 'block'
  },
  projects: [
    {name: 'desktop', use: {viewport: {width: 1440, height: 1000}}},
    {name: 'mobile', use: {viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true}}
  ]
});
