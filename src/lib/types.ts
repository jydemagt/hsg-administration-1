export interface Brand {
  id: string;
  name: string;
  description: string;
  created_at: string;
}

export interface Category {
  id: string;
  name: string;
  description: string;
  created_at: string;
}

export interface Location {
  id: string;
  name: string;
  address: string;
  created_at: string;
}

export interface Product {
  id: string;
  sku: string;
  name: string;
  description: string;
  brand_id: string | null;
  category_id: string | null;
  price: number;
  cost: number;
  reorder_level: number;
  image_url: string;
  created_at: string;
  updated_at: string;
}