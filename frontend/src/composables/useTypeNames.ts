import { useI18n } from 'vue-i18n'
import type { RootProduct } from '@/stores/industry/types'

interface NamedProject {
  displayName: string | null
  productTypeId: number
  productTypeName: string | null
  rootProducts?: RootProduct[]
}

export function useTypeNames() {
  const { t } = useI18n()

  function typeNameLabel(typeName: string | null, typeId: number): string {
    return typeName ?? t('common.unknownTypeName', { typeId })
  }

  function rootProductsLabel(rootProducts: RootProduct[]): string {
    if (rootProducts.length === 1) {
      const [product] = rootProducts
      const name = typeNameLabel(product.typeName, product.typeId)
      return (product.count ?? 1) > 1 ? `${name} ×${product.count}` : name
    }
    return rootProducts.map(product => typeNameLabel(product.typeName, product.typeId)).join(' + ')
  }

  function projectDisplayName(project: NamedProject): string {
    if (project.displayName !== null) return project.displayName
    if (project.rootProducts && project.rootProducts.length > 0) return rootProductsLabel(project.rootProducts)
    return typeNameLabel(project.productTypeName, project.productTypeId)
  }

  return { typeNameLabel, projectDisplayName }
}
