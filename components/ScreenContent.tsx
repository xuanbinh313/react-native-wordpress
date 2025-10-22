import { gql, TypedDocumentNode } from '@apollo/client';
import { useQuery } from '@apollo/client/react';
import React from 'react';
import { Text, View } from 'react-native';
import { EditScreenInfo } from './EditScreenInfo';

type ScreenContentProps = {
  title: string;
  path: string;
  children?: React.ReactNode;
};
// In a real application, consider generating types from your schema
// instead of writing them by hand
type GetProductsQuery = {
  products: {
    edges: Array<{
      node: {
        id: string;
        name: string;
        description: string;
      };
    }>;
  };
};
type GetProductsQueryVariables = Record<string, never>;
const GET_PRODUCTS: TypedDocumentNode<GetProductsQuery, GetProductsQueryVariables> = gql`
  query getProducts {
    products {
      edges {
        node {
          id
          name
          slug
          description
        }
      }
    }
  }
`;
export const ScreenContent = ({ title, path, children }: ScreenContentProps) => {
  const { loading, error, data } = useQuery(GET_PRODUCTS);
  const products = data?.products || { edges: [] };
  if (error) return <p>Error : {error.message}</p>;
  return (
    <View className={styles.container}>
      <Text className={styles.title}>{title}</Text>
      {loading ? <p>Loading...</p> : null}
      {products.edges.map(({ node }: any) => (
        <View key={node.id} className="my-2 w-4/5 rounded-lg border border-gray-300 p-4">
          <Text className="text-lg font-semibold">{node.name}</Text>
          <Text className="text-gray-600">{node.description}</Text>
        </View>
      ))}
      <View className={styles.separator} />
      <EditScreenInfo path={path} />
      {children}
    </View>
  );
};
const styles = {
  container: `items-center flex-1 justify-center bg-white`,
  separator: `h-[1px] my-7 w-4/5 bg-gray-200`,
  title: `text-xl font-bold`,
};
