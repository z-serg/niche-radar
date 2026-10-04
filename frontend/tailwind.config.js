/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        // Нейтральная схема; рост/падение различаются не только цветом
        // (стрелки и подписи, ТЗ §5).
        brand: {
          50: '#eef4ff',
          100: '#dce7fd',
          200: '#c0d3fc',
          300: '#94b6fa',
          400: '#6191f6',
          500: '#3d6def',
          600: '#2750e2',
          700: '#1f3fd1',
          800: '#2036ab',
          900: '#203587',
        },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'Segoe UI', 'Roboto', 'sans-serif'],
        mono: ['JetBrains Mono', 'ui-monospace', 'monospace'],
      },
    },
  },
  plugins: [],
};
