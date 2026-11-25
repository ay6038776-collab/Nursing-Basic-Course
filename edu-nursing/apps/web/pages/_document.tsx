import { Html, Head, Main, NextScript } from 'next/document';

export default function Document() {
  return (
    <Html dir="rtl" lang="ar">
      <Head>
        <meta charSet="utf-8" />
        <meta name="description" content="Edu Nursing – Medical Education Academy – منصة التعليم الطبي والتمريض" />
        <link rel="manifest" href="/manifest.json" />
        <link rel="icon" href="/favicon.ico" />
      </Head>
      <body>
        <Main />
        <NextScript />
      </body>
    </Html>
  );
}
