import { useRouter } from 'expo-router';
import { Button } from 'react-native';

export default function Home() {
  const router = useRouter();

  return <Button title="Go to About" onPress={() => router.navigate('/about')} />;
}