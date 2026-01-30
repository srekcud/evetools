/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{vue,js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      fontFamily: {
        'display': ['Rajdhani', 'sans-serif'],
        'mono': ['Share Tech Mono', 'monospace'],
      },
      colors: {
        eve: {
          dark: '#0a0c0f',
          darker: '#050607',
          accent: '#00d4ff',
          'accent-dim': '#0099bb',
          warning: '#ff6b35',
          success: '#00ff88',
          text: '#8892a2',
          'text-bright': '#c5cdd8',
        },
      },
      backgroundImage: {
        'eve-gradient': 'linear-gradient(135deg, #0a0c0f 0%, #141820 50%, #0a0c0f 100%)',
        'eve-card': 'linear-gradient(180deg, rgba(20, 24, 32, 0.8) 0%, rgba(10, 12, 15, 0.9) 100%)',
      },
      animation: {
        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
        'glow': 'glow 2s ease-in-out infinite alternate',
      },
      keyframes: {
        glow: {
          '0%': { boxShadow: '0 0 5px rgba(0, 212, 255, 0.2)' },
          '100%': { boxShadow: '0 0 20px rgba(0, 212, 255, 0.4)' },
        },
      },
    },
  },
  plugins: [],
}
