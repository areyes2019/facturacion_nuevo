import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import tseslint from 'typescript-eslint'
import eslintConfigPrettier from 'eslint-config-prettier'
import globals from 'globals'

export default tseslint.config(
  { ignores: ['dist/**'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  ...pluginVue.configs['flat/recommended'],
  {
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: globals.browser,
      parserOptions: {
        parser: tseslint.parser,
      },
    },
  },
  {
    files: ['src/**/*.vue'],
    rules: {
      // Reka UI reserva la cadena vacía para limpiar la selección de un Select,
      // así que un SelectItem con value="" lanza al montarse y su opción nunca
      // llega a la lista (ver specs/003-design-system-tailwind.md).
      'vue/no-restricted-static-attribute': [
        'error',
        {
          key: 'value',
          value: '/^$/',
          element: 'SelectItem',
          message:
            'Reka UI reserva "" para limpiar la selección: declara la opción con un valor centinela (ver specs/003-design-system-tailwind.md).',
        },
      ],
    },
  },
  {
    // Componentes vendored de shadcn-vue: se copian tal cual desde el
    // registry y siguen su propia convención (nombres de un solo término,
    // props opcionales sin default), no la de nuestro código de app.
    files: ['src/components/ui/**/*.vue'],
    rules: {
      'vue/multi-word-component-names': 'off',
      'vue/require-default-prop': 'off',
    },
  },
  eslintConfigPrettier,
)
