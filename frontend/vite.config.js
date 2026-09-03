import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
export default defineConfig({
  plugins:[react()],
  base:'/app/',
  build:{outDir:'../backend/public/app',emptyOutDir:true,sourcemap:false}
})
