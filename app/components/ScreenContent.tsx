import { gql, TypedDocumentNode } from '@apollo/client';
import { useQuery } from '@apollo/client/react';
import React from 'react';
import { FlatList, Image, ScrollView, Text, View } from 'react-native';
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
        image?: {
          altText: string;
          sourceUrl: string;
        };
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
          description(format: RAW)
          name
          image {
            altText
            sourceUrl
          }
        }
      }
    }
  }
`;
export const ScreenContent = ({ title, path, children }: ScreenContentProps) => {
  const { loading, error, data } = useQuery(GET_PRODUCTS);
  const products = data?.products || { edges: [] };
  if (error) return <Text>Error : {error.message}</Text>;
  return (
    <ScrollView contentContainerStyle={{ alignItems: 'center', paddingVertical: 16 }}>
      <Text className={styles.title}>{title}</Text>
      {loading ? <Text>Loading...</Text> : null}
      <FlatList
        className="w-full"
        data={products.edges}
        keyExtractor={({ node }: any) => node.id}
        renderItem={({ item }) => {
          const { node } = item;
          return (
            <View key={node.id} className="my-2 w-4/5 rounded-lg border border-gray-300 p-4">
              <Text className="text-lg font-semibold">{node.name}</Text>
              <Text className="text-gray-600">{node.description}</Text>
              {node?.image?.sourceUrl ? (
                <Image
                  className="mt-2 h-48 w-full rounded-md"
                  source={{ uri: node.image.sourceUrl }}
                  resizeMode="cover"
                />
              ) : null}
            </View>
          );
        }}
      />
      <View className={styles.separator} />
      <EditScreenInfo path={path} />
      {children}
    </ScrollView>
  );
};
const styles = {
  container: `items-center flex-1 justify-center bg-white`,
  separator: `h-[1px] my-7 w-4/5 bg-gray-200`,
  title: `text-xl font-bold`,
  label: `items-center text-sm font-medium select-none group-data-[disabled=true]:pointer-events-none group-data-[disabled=true]:opacity-50 peer-disabled:cursor-not-allowed peer-disabled:opacity-50 group/field-label peer/field-label flex w-fit gap-2 leading-snug group-data-[disabled=true]/field:opacity-50 has-[>[data-slot=field]]:w-full has-[>[data-slot=field]]:flex-col has-[>[data-slot=field]]:rounded-md has-[>[data-slot=field]]:border [&>*]:data-[slot=field]:p-4 has-data-[state=checked]:bg-primary/5 has-data-[state=checked]:border-primary dark:has-data-[state=checked]:bg-primary/10`,
  input:
    'file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive',
};
