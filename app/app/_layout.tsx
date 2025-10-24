import React from 'react';
import { Slot } from 'expo-router';
import { ApolloProvider } from '@apollo/client/react';
import { client } from 'lib/apollo';
import { StatusBar } from 'expo-status-bar';
import '../global.css';

export default function RootLayout() {
  return (
    <ApolloProvider client={client}>
      <Slot />
      <StatusBar style="auto" />
    </ApolloProvider>
  );
}
